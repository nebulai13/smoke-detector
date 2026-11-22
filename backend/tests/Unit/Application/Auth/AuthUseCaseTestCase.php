<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\Authenticate;
use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\Auth\ConfirmationLinks;
use SmokeDetector\Application\Auth\FailedLogins;
use SmokeDetector\Application\Auth\GivenConsent;
use SmokeDetector\Application\Auth\LogIn;
use SmokeDetector\Application\Auth\LogOut;
use SmokeDetector\Application\Auth\MailBudget;
use SmokeDetector\Application\Auth\OneTimeTokens;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Auth\RefreshSession;
use SmokeDetector\Application\Auth\RegisterUser;
use SmokeDetector\Application\Auth\Registration;
use SmokeDetector\Application\Auth\RequestPasswordReset;
use SmokeDetector\Application\Auth\ResendVerification;
use SmokeDetector\Application\Auth\ResetPassword;
use SmokeDetector\Application\Auth\Session;
use SmokeDetector\Application\Auth\Sessions;
use SmokeDetector\Application\Auth\TokenHash;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Auth\VerifyEmail;
use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Application\RateLimit\RateLimit;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\Fake\FakeAccessTokens;
use SmokeDetector\Tests\Support\Fake\FakeMailValuesCipher;
use SmokeDetector\Tests\Support\Fake\FakePasswordHasher;
use SmokeDetector\Tests\Support\Fake\FakeRefreshSecrets;
use SmokeDetector\Tests\Support\Fake\ListingMailTemplates;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use SmokeDetector\Tests\Support\Fake\SequenceTokenGenerator;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\InMemory\InMemoryConsentRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryOneTimeTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryRateLimitCounters;
use SmokeDetector\Tests\Support\InMemory\InMemoryRefreshTokenRepository;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The world of the auth use cases, without any I/O: in-memory repositories that roll back like the
 * database, a mail outbox that keeps the mails (no use case sends one itself), a clock that stands
 * still at 2026-11-17 12:00:00 UTC, and predictable tokens ("token-1", …), refresh token secrets ("secret-<generation>-of-<key>"),
 * ids and password hashes ("fake-v1:<password>").
 */
abstract class AuthUseCaseTestCase extends TestCase
{
    protected const string CLIENT = '203.0.113.7';
    protected const string PASSWORD = 'correct horse battery';

    /** The versions of the consent texts in force. A registration is made with them unless a test says otherwise. */
    protected const array CONSENT_VERSIONS = ['age18' => 'age-v1', 'art9' => 'art9-v2', 'privacy' => 'privacy-v3'];

    protected FrozenClock $clock;
    protected InMemoryTransactionRunner $transactions;
    protected InMemoryUserRepository $users;
    protected InMemoryConsentRepository $consents;
    protected InMemoryOneTimeTokenRepository $oneTimeTokens;
    protected InMemoryRefreshTokenRepository $refreshTokens;
    protected InMemoryRateLimitCounters $counters;
    protected InMemoryMailOutbox $outbox;
    protected FakeMailValuesCipher $cipher;
    protected FakePasswordHasher $hasher;
    protected SequenceTokenGenerator $tokens;
    protected SequentialIdGenerator $ids;
    protected FakeAccessTokens $accessTokens;
    protected FakeRefreshSecrets $refreshSecrets;
    protected RecordingLogger $logger;

    /** Mails get ids of their own row of numbers, so that queuing a mail does not shift the ids of accounts and tokens. */
    private SequentialIdGenerator $mailIds;

    protected function setUp(): void
    {
        $this->clock = FrozenClock::at('2026-11-17 12:00:00');
        $this->transactions = new InMemoryTransactionRunner();
        $this->users = new InMemoryUserRepository($this->transactions);
        $this->consents = new InMemoryConsentRepository($this->transactions);
        $this->oneTimeTokens = new InMemoryOneTimeTokenRepository($this->transactions);
        $this->refreshTokens = new InMemoryRefreshTokenRepository($this->transactions);
        $this->counters = new InMemoryRateLimitCounters($this->transactions);
        $this->outbox = new InMemoryMailOutbox($this->transactions);
        $this->cipher = new FakeMailValuesCipher();
        $this->mailIds = new SequentialIdGenerator();
        $this->hasher = new FakePasswordHasher();
        $this->tokens = new SequenceTokenGenerator();
        $this->ids = new SequentialIdGenerator();
        $this->accessTokens = new FakeAccessTokens();
        $this->refreshSecrets = new FakeRefreshSecrets();
        $this->logger = new RecordingLogger();
    }

    protected function registerUser(): RegisterUser
    {
        return new RegisterUser(
            $this->transactions,
            $this->clock,
            $this->mailBudget(),
            new PasswordPolicy(),
            $this->hasher,
            $this->users,
            $this->consents,
            $this->oneTimeTokensService(),
            $this->ids,
            $this->accountMails(),
            new ConsentVersions(self::CONSENT_VERSIONS['age18'], self::CONSENT_VERSIONS['art9'], self::CONSENT_VERSIONS['privacy']),
        );
    }

    protected function verifyEmail(): VerifyEmail
    {
        return new VerifyEmail(
            $this->transactions,
            $this->clock,
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            $this->hasher,
            $this->oneTimeTokensService(),
            $this->users,
            $this->sessions(),
            $this->logger,
        );
    }

    /**
     * Confirms a registration the way the confirmation page does: the token of the link plus the
     * password of the sign-up.
     */
    protected function confirm(string $token, string $password = self::PASSWORD, string $client = self::CLIENT): Session
    {
        return $this->verifyEmail()->execute($token, $password, null, $client);
    }

    protected function authenticate(): Authenticate
    {
        return new Authenticate($this->accessTokens, $this->users);
    }

    protected function confirmationLinks(): ConfirmationLinks
    {
        return new ConfirmationLinks($this->users, $this->oneTimeTokensService(), $this->accountMails());
    }

    protected function purgeExpiredData(): PurgeExpiredData
    {
        return new PurgeExpiredData($this->transactions, $this->clock, $this->users, $this->oneTimeTokens, $this->refreshTokens, $this->counters, $this->outbox);
    }

    protected function resendVerification(): ResendVerification
    {
        return new ResendVerification(
            $this->transactions,
            $this->clock,
            $this->mailBudget(),
            $this->users,
            $this->confirmationLinks(),
            $this->accountMails(),
        );
    }

    protected function logIn(): LogIn
    {
        return new LogIn(
            $this->transactions,
            $this->clock,
            $this->failedLogins(),
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            $this->hasher,
            $this->users,
            $this->sessions(),
            $this->confirmationLinks(),
            $this->logger,
        );
    }

    protected function refreshSession(): RefreshSession
    {
        return new RefreshSession($this->transactions, $this->clock, $this->refreshTokens, $this->users, $this->sessions());
    }

    protected function logOut(): LogOut
    {
        return new LogOut($this->transactions, $this->clock, $this->refreshTokens, $this->users, $this->sessions());
    }

    protected function requestPasswordReset(): RequestPasswordReset
    {
        return new RequestPasswordReset(
            $this->transactions,
            $this->clock,
            $this->mailBudget(),
            $this->users,
            $this->oneTimeTokensService(),
            $this->accountMails(),
        );
    }

    protected function resetPassword(): ResetPassword
    {
        return new ResetPassword(
            $this->transactions,
            $this->clock,
            new PasswordPolicy(),
            $this->hasher,
            $this->oneTimeTokensService(),
            $this->users,
            $this->refreshTokens,
            $this->failedLogins(),
            $this->logger,
        );
    }

    protected function failedLogins(): FailedLogins
    {
        return new FailedLogins(new RateLimiter($this->counters, RateLimitKey::hasher()));
    }

    protected function mailBudget(): MailBudget
    {
        return new MailBudget(new RateLimiter($this->counters, RateLimitKey::hasher()));
    }

    protected function oneTimeTokensService(): OneTimeTokens
    {
        return new OneTimeTokens($this->oneTimeTokens, $this->tokens, $this->ids);
    }

    protected function sessions(): Sessions
    {
        return new Sessions($this->refreshTokens, $this->tokens, $this->refreshSecrets, $this->accessTokens, $this->ids);
    }

    protected static function registration(
        string $email = 'anna@example.org',
        string $password = self::PASSWORD,
        string $displayName = 'Anna',
        string $avatar = 'avatar-03',
        Locale $locale = Locale::De,
        string $timeZone = 'Europe/Berlin',
        bool $age18 = true,
        bool $art9 = true,
        bool $privacy = true,
        string $age18Version = self::CONSENT_VERSIONS['age18'],
        string $art9Version = self::CONSENT_VERSIONS['art9'],
        string $privacyVersion = self::CONSENT_VERSIONS['privacy'],
    ): Registration {
        return new Registration(
            EmailAddress::fromInput($email),
            $password,
            DisplayName::fromInput($displayName),
            Avatar::fromInput($avatar),
            $locale,
            TimeZone::fromInput($timeZone),
            new GivenConsent($age18, $age18Version),
            new GivenConsent($art9, $art9Version),
            new GivenConsent($privacy, $privacyVersion),
        );
    }

    /**
     * Stores an account directly, without going through registration.
     */
    protected function givenUser(
        string $email = 'anna@example.org',
        string $password = self::PASSWORD,
        bool $verified = true,
        Locale $locale = Locale::De,
        ?string $passwordHash = null,
    ): User {
        $user = new User(
            UserId::fromString($this->ids->next()),
            EmailAddress::fromInput($email),
            $passwordHash ?? "fake-v1:{$password}",
            DisplayName::fromInput('Stored Name'),
            Avatar::fromInput('avatar-07'),
            $locale,
            TimeZone::fromInput('Europe/Vienna'),
            $verified ? new DateTimeImmutable('2026-11-01 08:00:00 UTC') : null,
            // A registration that is not confirmed was submitted an hour ago: its 24 hours are running.
            $verified ? new DateTimeImmutable('2026-11-01 07:00:00 UTC') : $this->now()->modify('-1 hour'),
        );
        $this->users->add($user);

        return $user;
    }

    protected static function email(string $address = 'anna@example.org'): EmailAddress
    {
        return EmailAddress::fromInput($address);
    }

    protected function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * The mails that wait in the outbox, made as the sender will make them (with ListingMailTemplates:
     * subject "<template> [<locale>]", text one "name=value" per line), in the order they were
     * queued. This is what the cron job mails within a minute.
     *
     * @return list<Mail>
     */
    protected function queuedMails(): array
    {
        $templates = new ListingMailTemplates();

        return array_map(
            fn (QueuedMail $mail): Mail => $templates->render($mail->kind->value, $mail->locale, $mail->to, $this->cipher->open($mail->sealedValues)),
            $this->outbox->all(),
        );
    }

    /**
     * The only mail that was queued. Fails when there is none or more than one.
     */
    protected function theMail(): Mail
    {
        $mails = $this->queuedMails();
        self::assertCount(1, $mails);

        return $mails[0];
    }

    /**
     * The current hits of a rate limit for a subject.
     */
    protected function hits(RateLimit $limit, string $subject, ?string $from = null): int
    {
        $start = intdiv($this->now()->getTimestamp(), $limit->windowSeconds) * $limit->windowSeconds;

        return $this->counters->hits(RateLimitKey::of($limit->name, $subject, $from), new DateTimeImmutable('@' . $start));
    }

    /**
     * The failed logins counted for an address from a client: under the rule of the client, of the
     * pair and of the address.
     *
     * @return array{client: int, pair: int, address: int}
     */
    protected function failedLoginsCounted(string $email, string $client): array
    {
        return [
            'client' => $this->hits(AuthLimits::loginFailuresPerClient(), $client),
            'pair' => $this->hits(AuthLimits::loginFailuresPerPair(), $email, $client),
            'address' => $this->hits(AuthLimits::loginFailuresPerAddress(), $email),
        ];
    }

    /**
     * Asserts that no rate limit counter of the mail-sending endpoints or of login holds a hit.
     */
    protected function assertNothingCounted(): void
    {
        self::assertSame([], $this->counters->all());
    }

    protected static function hashOf(string $token): string
    {
        return TokenHash::of($token);
    }

    /**
     * Whether a one-time token is stored (by its hash) for the purpose.
     */
    protected function hasOneTimeToken(string $token, TokenPurpose $purpose): bool
    {
        foreach ($this->oneTimeTokens->all() as $stored) {
            if ($stored->tokenHash === TokenHash::of($token) && $stored->purpose === $purpose) {
                return true;
            }
        }

        return false;
    }

    protected function accountMails(): AccountMails
    {
        return new AccountMails($this->outbox, $this->cipher, $this->mailIds, SettingsFixture::settings()->appUrl);
    }
}
