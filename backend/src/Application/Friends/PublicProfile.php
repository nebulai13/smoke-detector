<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;

/**
 * What one user sees of another before they are friends, and never more than this (HLD §4.7):
 * the id, the name and the avatar. No address, no time zone, nothing about the smoking.
 */
final readonly class PublicProfile
{
    public function __construct(
        public UserId $userId,
        public DisplayName $displayName,
        public Avatar $avatar,
    ) {
    }

    public static function of(User $user): self
    {
        return new self($user->id, $user->displayName, $user->avatar);
    }

    /**
     * The order of names in a list: without regard to capitals (also with accents), then by id.
     */
    public static function compareByName(self $a, self $b): int
    {
        return strcmp(mb_strtolower($a->displayName->value), mb_strtolower($b->displayName->value))
            ?: strcmp($a->userId->value, $b->userId->value);
    }
}
