<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\RateLimit\RateLimit;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\TransactionRunner;

/**
 * POST /feed/reactions (HLD §4.13, SRS FR-SOC-02, FR-SOC-04): a user reacts to an entry of a friend
 * with one of the preset reactions. One reaction per user and entry: sent again, it changes its key.
 * A new one earns XP and counts for the badge (ReactionRewards); a changed one earns nothing.
 *
 * Only an entry the user sees in the feed at that moment can be reacted to (FeedVisibility, the rule
 * of ShowFeed), and never an own one.
 */
final readonly class React
{
    /** Reactions and nudges together, per user and day in UTC (FR-SOC-04). */
    public const int DAILY_ACTIONS = 30;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private FriendLinks $links,
        private FeedEvents $events,
        private UserSettings $settings,
        private Reactions $reactions,
        private RateLimiter $limiter,
        private ReactionRewards $rewards,
    ) {
    }

    /**
     * @throws AccountNotFound   The account of $userId was deleted in the meantime.
     * @throws RateLimitExceeded The user has had DAILY_ACTIONS today. A refused request counts too.
     * @throws FeedEntryNotFound There is no such entry, or the user may not see it: the same answer.
     * @throws OwnFeedEntry      The entry is about the user.
     */
    public function execute(UserId $userId, string $eventId, ReactionKey $key): ReactionResult
    {
        $now = $this->clock->now();

        // Looked up before counting: nothing should be counted for an account that is gone (as in
        // ExportAccount). Counted outside the transaction, so that a refused request stays counted:
        // a counter is never part of a transaction (RateLimitCounters).
        if ($this->users->find($userId) === null) {
            throw new AccountNotFound();
        }
        $this->limiter->consume(self::limit(), $userId->value, $now);

        return $this->transactions->run(function () use ($userId, $eventId, $key, $now): ReactionResult {
            // Like every step that changes the progress: two reactions of one user, one after the other.
            if ($this->users->lock($userId) === null) {
                throw new AccountNotFound();
            }
            $event = $this->events->find($eventId);
            $visibility = FeedVisibility::of($userId, $this->users, $this->links, $this->settings);
            if ($event === null || $visibility->entryOf($event) === null) {
                throw new FeedEntryNotFound();
            }
            if ($event->userId->equals($userId)) {
                throw new OwnFeedEntry();
            }
            $isNew = $this->reactions->save(new Reaction($event->id, $userId, $key, $now));

            return new ReactionResult($key, $isNew ? $this->rewards->newReaction($userId, $now) : 0);
        });
    }

    private static function limit(): RateLimit
    {
        return new RateLimit('social.actions', self::DAILY_ACTIONS, 86400);
    }
}
