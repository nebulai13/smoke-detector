<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;

/**
 * The duels of all users (HLD §4.3 "duels").
 */
interface Duels
{
    public function find(string $id): ?Duel;

    /**
     * Stores the duel, replacing the one with the same id.
     */
    public function save(Duel $duel): void;

    /**
     * Deleting a duel that does not exist does nothing.
     */
    public function delete(string $id): void;

    /**
     * @return list<Duel> The duels the user is part of, on either side, the newest first; made in the
     *                    same second, the larger id first. At most $limit.
     */
    public function ofUser(UserId $userId, int $limit): array;

    /**
     * @return list<Duel> The proposals (also those whose time is over) and the running duels the
     *                    user is part of, the oldest first; made in the same second, the smaller id first.
     */
    public function openOf(UserId $userId): array;

    /**
     * @return list<Duel> The proposals whose time is over at $now, the one that is over longest
     *                    first, then by id. At most $limit.
     */
    public function expired(DateTimeImmutable $now, int $limit): array;

    /**
     * @return list<Duel> The running duels whose last day is final for both users (it is before the
     *                    next day to finalize of each), the one accepted first first, then by id.
     *                    At most $limit.
     */
    public function decidable(int $limit): array;
}
