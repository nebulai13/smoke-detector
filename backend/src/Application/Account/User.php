<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * An account as it is stored (HLD §4.3 "users"). Immutable: a change is a new object, written back
 * with UserRepository::update().
 */
final readonly class User
{
    /** FR-ACC-03: how long a registration may stay unconfirmed. Its newest link is valid as long. */
    public const int UNCONFIRMED_LIFETIME_SECONDS = 86_400;

    public function __construct(
        public UserId $id,
        public EmailAddress $email,
        /** The output of the PasswordHasher. Never leaves the server and never goes into a log. */
        #[SensitiveParameter]
        public string $passwordHash,
        public DisplayName $displayName,
        public Avatar $avatar,
        public Locale $locale,
        public TimeZone $timeZone,
        /** Null while the registration is not confirmed. Such an account cannot be used (FR-ACC-03). */
        public ?DateTimeImmutable $emailVerifiedAt,
        /**
         * For an unconfirmed registration: when its newest confirmation link was mailed (by
         * registering, registering again, or asking for the link again). It is deleted 24 h later.
         */
        public DateTimeImmutable $createdAt,
        /**
         * Counts the password resets. Every access token carries the number it was issued under,
         * and only tokens with the current number are accepted: a reset signs out everywhere, at
         * once (FR-ACC-05).
         */
        public int $tokenVersion = 0,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    /**
     * Whether this is a registration that can still be confirmed: not confirmed yet, and its 24
     * hours are not over. After them it is as good as deleted, whether or not the cleanup has come
     * by: it gets no new link and cannot be confirmed any more.
     */
    public function isPendingAt(DateTimeImmutable $now): bool
    {
        return !$this->isVerified()
            && $now->getTimestamp() - $this->createdAt->getTimestamp() < self::UNCONFIRMED_LIFETIME_SECONDS;
    }

    public function verifiedAt(DateTimeImmutable $at): self
    {
        return $this->with(emailVerifiedAt: $at);
    }

    /**
     * For an unconfirmed registration that is mailed a new link: its 24 hours start again.
     */
    public function registeredAt(DateTimeImmutable $at): self
    {
        return $this->with(createdAt: $at);
    }

    /**
     * What the user may change about the account (FR-ACC-09). The email address is not among it:
     * a new one would have to be confirmed first.
     */
    public function withProfile(DisplayName $displayName, Avatar $avatar, Locale $locale, TimeZone $timeZone): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $displayName,
            $avatar,
            $locale,
            $timeZone,
            $this->emailVerifiedAt,
            $this->createdAt,
            $this->tokenVersion,
        );
    }

    public function withPasswordHash(#[SensitiveParameter] string $passwordHash): self
    {
        return $this->with(passwordHash: $passwordHash);
    }

    /**
     * Makes every access token that is out for this account worthless.
     */
    public function withNextTokenVersion(): self
    {
        return $this->with(tokenVersion: $this->tokenVersion + 1);
    }

    private function with(
        #[SensitiveParameter]
        ?string $passwordHash = null,
        ?DateTimeImmutable $emailVerifiedAt = null,
        ?DateTimeImmutable $createdAt = null,
        ?int $tokenVersion = null,
    ): self {
        return new self(
            $this->id,
            $this->email,
            $passwordHash ?? $this->passwordHash,
            $this->displayName,
            $this->avatar,
            $this->locale,
            $this->timeZone,
            $emailVerifiedAt ?? $this->emailVerifiedAt,
            $createdAt ?? $this->createdAt,
            $tokenVersion ?? $this->tokenVersion,
        );
    }
}
