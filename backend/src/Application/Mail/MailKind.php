<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Auth\TokenPurpose;

/**
 * The mails the application sends. The value is the name of the template (templates/mail/<locale>/
 * <name>.php), and it is what the outbox stores and a log may name.
 */
enum MailKind: string
{
    case ConfirmEmail = 'confirm_email';
    case AlreadyRegistered = 'already_registered';
    case ResetPassword = 'reset_password';

    /**
     * The longest any mail is worth sending: as long as a confirmation link works, which is as long
     * as a registration may wait to be confirmed. For the cleanup of the outbox, which needs it as
     * a constant (lifetimeSeconds() of ConfirmEmail is the same number).
     */
    public const int LONGEST_LIFETIME_SECONDS = User::UNCONFIRMED_LIFETIME_SECONDS;

    /**
     * How long the mail is worth sending, counted from the moment it was queued: as long as the
     * token of its link works (the same numbers, TokenPurpose). After that the outbox drops it.
     *
     * The note about an existing account has no token. It answers a sign-up or a request for a
     * confirmation link, so it is worth as long as the confirmation mail it stands in for.
     */
    public function lifetimeSeconds(): int
    {
        return match ($this) {
            self::ConfirmEmail, self::AlreadyRegistered => TokenPurpose::VerifyEmail->lifetimeSeconds(),
            self::ResetPassword => TokenPurpose::ResetPassword->lifetimeSeconds(),
        };
    }
}
