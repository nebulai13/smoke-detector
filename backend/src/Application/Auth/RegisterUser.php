<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\EmailAlreadyRegistered;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Config\ConsentVersions;

/**
 * POST /auth/register: registration, confirm first (HLD §4.5 "Registration", FR-ACC-01 to 03).
 *
 * Whatever the state of the email address, the caller learns nothing: the method returns, and one
 * mail is queued for the address (unless the address has had its mails for this hour, see
 * MailBudget). What the mail says is the only difference. The mail is not sent here: it goes into
 * the outbox in the transaction that stores everything else, and the cron job sends it within a
 * minute or two (AccountMails, SendQueuedMails).
 *
 * - New address: an unconfirmed account, its consents, and a mail with the confirmation link.
 * - Address of a confirmed account: nothing is changed. The owner is told that someone tried.
 * - Address of an unconfirmed registration: its data is replaced by this submission, the older
 *   link stops working, and a new one is mailed.
 */
final readonly class RegisterUser
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private MailBudget $mailBudget,
        private PasswordPolicy $passwordPolicy,
        private PasswordHasher $hasher,
        private UserRepository $users,
        private ConsentRepository $consents,
        private OneTimeTokens $oneTimeTokens,
        private IdGenerator $ids,
        private AccountMails $mails,
        private ConsentVersions $consentVersions,
    ) {
    }

    /**
     * @param string $clientAddress Where the request comes from, for the rate limit.
     *
     * @throws ValidationFailed  When the password is not acceptable, a consent is missing, or a
     *                           consent names another version of its text than the one in force.
     * @throws RateLimitExceeded When the client address has asked for too many mails, in all or to
     *                           this email address (see MailBudget).
     */
    public function execute(Registration $registration, string $clientAddress): void
    {
        $this->assertAcceptable($registration);

        $now = $this->clock->now();
        $mailLeft = $this->mailBudget->spend($registration->email, $clientAddress, $now);

        // Hashed before the account is looked up, and also when the hash will not be needed: hashing
        // is by far the slowest step, and how long the answer takes must tell neither whether the
        // address has an account nor whether others have used up its mails for this hour.
        $passwordHash = $this->hasher->hash($registration->password);

        if (!$mailLeft) {
            // The address has had its mails for this hour, from whoever. The caller is answered as
            // always, and nothing is stored: a registration that is waiting keeps its data and its
            // link, the one of the last mail that went out.
            return;
        }

        $store = function () use ($registration, $passwordHash, $now): void {
            // Which account to lock is looked up before the transaction, never inside it: MariaDB
            // 11.8 gives a transaction up (error 1020) when it locks a row that was changed after
            // the transaction's first plain read, which is what a registration of the same address
            // a moment earlier does.
            $found = $this->users->findByEmail($registration->email);

            $this->transactions->run(function () use ($registration, $found, $passwordHash, $now): void {
                $this->store($registration, $found?->id, $passwordHash, $now);
            });
        };
        try {
            $store();
        } catch (EmailAlreadyRegistered) {
            // Another request registered the same new address between our lookup and our insert.
            // The second try finds that registration and replaces it, as if it had come a moment later.
            $store();
        }
    }

    /**
     * What is wrong with one consent of a registration (FR-ACC-02, LEG-03). It must be given, and it
     * must be a consent to the version of its text that is in force here (settings consents.*). The
     * app sends the version of the text it showed. Another version means the user saw another text
     * (an app that was not updated): storing that as a consent to the current text would make the
     * record say something that did not happen.
     *
     * Public, so that the action can ask before it calls execute() and list everything that is
     * wrong with a form in one answer.
     *
     * @return list<FieldError> Empty when the consent is fine.
     */
    public function consentViolations(ConsentType $type, GivenConsent $consent): array
    {
        $inForce = match ($type) {
            ConsentType::Age18 => $this->consentVersions->age18,
            ConsentType::Art9 => $this->consentVersions->art9,
            ConsentType::Privacy => $this->consentVersions->privacy,
        };

        $errors = [];
        if (!$consent->accepted) {
            $errors[] = new FieldError("consents.{$type->value}.accepted", ErrorCode::NotAccepted, 'Must be accepted.');
        }
        if ($consent->version !== $inForce) {
            $errors[] = new FieldError("consents.{$type->value}.version", ErrorCode::OutdatedVersion, 'Is not the version of this text that is in force.');
        }

        return $errors;
    }

    /**
     * Reports everything that is wrong at once.
     */
    private function assertAcceptable(Registration $registration): void
    {
        $errors = [];

        $violation = $this->passwordPolicy->violation($registration->password);
        if ($violation !== null) {
            $errors[] = $violation->forField('password');
        }
        // FR-ACC-02: no account without all three consents.
        foreach (ConsentType::cases() as $type) {
            $errors = [...$errors, ...$this->consentViolations($type, $registration->consent($type))];
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * Inside the transaction. Whatever is stored, its mail is queued with it.
     *
     * @param UserId|null $found The account that had this address just before the transaction began.
     */
    private function store(Registration $registration, ?UserId $found, #[SensitiveParameter] string $passwordHash, DateTimeImmutable $now): void
    {
        // Read under a lock: what is decided below must still be true when it is written. An
        // account that is gone by now (the cleanup deleted it) is registered as a new one.
        $existing = $found === null ? null : $this->users->lock($found);

        if ($existing !== null && $existing->isVerified()) {
            // In the owner's language, not in the one this form was filled in with.
            $this->mails->alreadyRegistered($existing, $now);

            return;
        }

        $user = new User(
            $existing === null ? UserId::fromString($this->ids->next()) : $existing->id,
            $registration->email,
            $passwordHash,
            $registration->displayName,
            $registration->avatar,
            $registration->locale,
            $registration->timeZone,
            null,
            // Also for a replaced registration: the 24 hours to confirm it start again.
            $now,
        );
        if ($existing === null) {
            $this->users->add($user);
        } else {
            $this->users->update($user);
        }

        // The versions the app sent, which assertAcceptable() found to be the ones in force: what
        // is stored is what the user was shown.
        $consents = [
            new Consent(ConsentType::Age18, $registration->age18->version, $now),
            new Consent(ConsentType::Art9, $registration->art9->version, $now),
            new Consent(ConsentType::Privacy, $registration->privacy->version, $now),
        ];
        if ($existing === null) {
            $this->consents->add($user->id, $consents);
        } else {
            $this->consents->replaceAll($user->id, $consents);
        }

        // Withdraws the link of an earlier submission.
        $token = $this->oneTimeTokens->issue($user->id, TokenPurpose::VerifyEmail, $now);

        $this->mails->confirmEmail($user, $token, $now);
    }
}
