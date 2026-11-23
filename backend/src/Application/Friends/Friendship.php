<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;
use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;

/**
 * The link between two users (HLD §4.3 "friendships"): a friend request while nobody has answered
 * it, a friendship once it is accepted. Who asked and when stays as it was.
 */
final readonly class Friendship
{
    public function __construct(
        /** Who asked. */
        public UserId $requester,
        /** Who was asked, and answers. */
        public UserId $recipient,
        public DateTimeImmutable $requestedAt,
        /** Null while the request waits for its answer. */
        public ?DateTimeImmutable $acceptedAt = null,
    ) {
        if ($requester->equals($recipient)) {
            throw new InvalidArgumentException('Nobody is their own friend.');
        }
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    /**
     * Whether this is a request of $requester that waits for its answer.
     */
    public function isOpenRequestOf(UserId $requester): bool
    {
        return !$this->isAccepted() && $this->requester->equals($requester);
    }

    /**
     * The other one of the two, seen from $userId.
     */
    public function other(UserId $userId): UserId
    {
        return $this->requester->equals($userId) ? $this->recipient : $this->requester;
    }

    /**
     * The request, accepted at $at.
     */
    public function acceptedAt(DateTimeImmutable $at): self
    {
        return new self($this->requester, $this->recipient, $this->requestedAt, $at);
    }
}
