<?php

declare(strict_types=1);

namespace SmokeDetector\Application\RateLimit;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fixed-window rate limiting (HLD §4.5, NFR-05).
 *
 * A hit is counted first and judged afterwards, in one atomic step of the counter. So requests that
 * arrive at the same moment cannot all slip through before the first of them is counted.
 * Call it outside the use case's transaction (see RateLimitCounters).
 */
final readonly class RateLimiter
{
    public function __construct(
        private RateLimitCounters $counters,
        private RateLimitKeyHasher $hasher,
    ) {
    }

    /**
     * Counts one hit of the subject.
     *
     * @param string      $subject Who or what is limited: an email address, a client address. It is
     *                             stored as a keyed hash only (see key()).
     * @param string|null $from    For a rule about a pair: the place the subject is tried from (a
     *                             client address). The subject then has one counter per place, so
     *                             that what one client does to an email address is not held
     *                             against everybody else who uses that address.
     *
     * @throws RateLimitExceeded When this hit is one more than the rule allows in the window that
     *                           contains $now. The hit stays counted.
     */
    public function consume(RateLimit $limit, string $subject, DateTimeImmutable $now, ?string $from = null): void
    {
        if (!$this->tryConsume($limit, $subject, $now, $from)) {
            throw new RateLimitExceeded(
                self::windowStart($limit, $now)->getTimestamp() + $limit->windowSeconds - $now->getTimestamp(),
            );
        }
    }

    /**
     * Counts one hit of the subject, like consume(), and says whether it is within the limit. For
     * work that is left out silently over the limit, where refusing the request would give
     * something away.
     *
     * @return bool False when this hit is one more than the rule allows. The hit stays counted.
     */
    public function tryConsume(RateLimit $limit, string $subject, DateTimeImmutable $now, ?string $from = null): bool
    {
        return $this->counters->increment($this->key($limit, $subject, $from), self::windowStart($limit, $now)) <= $limit->maxHits;
    }

    /**
     * Takes back the hit that consume() counted with the same arguments, for rules that only count
     * failures. It can take back nothing but that one hit, so nobody's earlier failures are forgiven.
     */
    public function refund(RateLimit $limit, string $subject, DateTimeImmutable $now, ?string $from = null): void
    {
        $this->counters->decrement($this->key($limit, $subject, $from), self::windowStart($limit, $now));
    }

    /**
     * Deletes what one rule counted about a subject, in every window: the subject starts again at
     * zero. No other rule's counter of that subject is touched, also not that of a rule whose name
     * ends the same way ("login.mail.email" and "mail.email"), and no counter of the subject as
     * tried from one place. Call it outside the transaction.
     */
    public function reset(RateLimit $limit, string $subject): void
    {
        $this->counters->deleteKey($this->key($limit, $subject, null));
    }

    /**
     * Deletes everything that was counted about a subject, under every rule and in every window,
     * from whatever place it was tried. For account deletion (FR-ACC-08): a key is the hash of an
     * email address or a user id, which still points at the person for whoever has the key of the
     * hash. Call it outside the transaction, like consume().
     */
    public function forget(string $subject): void
    {
        $this->counters->deleteKeysEndingWith(':' . $this->subjectHash($subject));
    }

    /**
     * Windows are aligned to the epoch: with a window of 900 s they start at :00, :15, :30 and :45.
     */
    private static function windowStart(RateLimit $limit, DateTimeImmutable $now): DateTimeImmutable
    {
        $start = intdiv($now->getTimestamp(), $limit->windowSeconds) * $limit->windowSeconds;

        return (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The subject is hashed: the table then holds no email or IP address in plain text, and a key
     * has a fixed length whatever the subject is. The hash has a secret key (RateLimitKeyHasher,
     * derived from jwt.secret), so whoever reads the table without that key cannot test whether a
     * given address is in it. Whoever has the key can, so a key is still personal data: the rows
     * are short-lived and are deleted with the account (forget()) and by the cleanup.
     *
     * A pair is "<rule>:<hash of the place>:<hash of the subject>". The subject comes last, so that
     * forget() finds every key of a subject by its ending. The hash of the place is written in
     * base64url instead of hexadecimal (43 characters instead of 64): with the longest rule name
     * the key still fits the VARCHAR(191) of `rate_limits`.
     */
    private function key(RateLimit $limit, string $subject, ?string $from): string
    {
        $place = $from === null ? '' : rtrim(strtr(base64_encode($this->hasher->hash($from)), '+/', '-_'), '=') . ':';

        return $limit->name . ':' . $place . $this->subjectHash($subject);
    }

    /**
     * The last part of every key of the subject, in hexadecimal.
     */
    private function subjectHash(string $subject): string
    {
        return bin2hex($this->hasher->hash($subject));
    }
}
