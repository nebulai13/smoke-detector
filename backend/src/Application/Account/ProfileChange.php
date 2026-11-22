<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

/**
 * What a PATCH /me wants changed (FR-ACC-09). Null leaves a value as it is.
 */
final readonly class ProfileChange
{
    public function __construct(
        public ?DisplayName $displayName = null,
        public ?Avatar $avatar = null,
        public ?Locale $locale = null,
        public ?TimeZone $timeZone = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->displayName === null && $this->avatar === null && $this->locale === null && $this->timeZone === null;
    }

    public function applyTo(User $user): User
    {
        return $user->withProfile(
            $this->displayName ?? $user->displayName,
            $this->avatar ?? $user->avatar,
            $this->locale ?? $user->locale,
            $this->timeZone ?? $user->timeZone,
        );
    }
}
