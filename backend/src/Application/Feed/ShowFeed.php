<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * GET /feed (HLD §4.12 "What a user sees", SRS FR-SOC-01, FR-FRND-03): the newest entries about the
 * user and the user's friends that the user may see, the newest first.
 *
 * - Friends only: an open request, a removed friend and a blocked user are not in it. An entry whose
 *   account is gone is left out.
 * - A health milestone of a friend is shown as far as that friend's setting allows now; the own ones
 *   always.
 * - The opponent of a duel is named only to that user and to friends of that user.
 * - An entry with a value this version of the code does not know (after a roll-back) is left out.
 *
 * Locks nothing: one transaction only so that everything is read from one snapshot.
 */
final readonly class ShowFeed
{
    /** How many entries a user sees at most. */
    public const int LIMIT = 50;

    /**
     * How many entries are read at a time. Entries the user may not see take no place: the next ones
     * are read until LIMIT are found or none are left.
     */
    private const int READ = 4 * self::LIMIT;

    public function __construct(
        private TransactionRunner $transactions,
        private UserRepository $users,
        private FriendLinks $links,
        private FeedEvents $events,
        private UserSettings $settings,
    ) {
    }

    /**
     * @return list<FeedEntry> At most LIMIT, the newest first.
     */
    public function show(UserId $userId): array
    {
        return $this->transactions->run(function () use ($userId): array {
            $friendIds = [];
            foreach ($this->links->ofUser($userId) as $link) {
                if ($link->isAccepted()) {
                    $friendIds[] = $link->other($userId);
                }
            }
            // The accounts that exist, read once: whom an entry is about, and whom of the opponents
            // the user may see named, are among them.
            $profiles = [];
            foreach ([$userId, ...$friendIds] as $id) {
                $account = $this->users->find($id);
                if ($account !== null) {
                    $profiles[$id->value] = PublicProfile::of($account);
                }
            }
            // Each friend's own setting decides what of the milestones the user sees.
            $settings = $this->settings->findAll($friendIds);

            $entries = [];
            for ($skip = 0; ; $skip += self::READ) {
                $events = $this->events->newestOf([$userId, ...$friendIds], self::READ, $skip);
                foreach ($events as $event) {
                    $entry = self::entryOf($event, $userId, $profiles, $settings);
                    if ($entry === null) {
                        continue;
                    }
                    $entries[] = $entry;
                    if (count($entries) === self::LIMIT) {
                        return $entries;
                    }
                }
                // A page that is not full was the last one.
                if (count($events) < self::READ) {
                    return $entries;
                }
            }
        });
    }

    /**
     * @param array<string, PublicProfile> $profiles The user and the friends, by id: those whose account exists.
     * @param array<string, Settings>      $settings Of every friend, by id.
     *
     * @return FeedEntry|null Null for an entry the user may not see or this code cannot read.
     */
    private static function entryOf(FeedEvent $event, UserId $viewer, array $profiles, array $settings): ?FeedEntry
    {
        $user = $profiles[$event->userId->value] ?? null;
        if ($user === null) {
            return null;
        }
        $mine = $event->userId->equals($viewer);
        $detail = (string) $event->detail;
        $level = null;
        $badge = null;
        $milestone = null;
        // A duel has no detail. Of the others, a value the code does not know leaves the entry out.
        if ($event->type === FeedEventType::LevelUp) {
            $level = self::levelOf($detail);
            if ($level === null) {
                return null;
            }
        } elseif ($event->type === FeedEventType::BadgeEarned) {
            $badge = Badge::tryFrom($detail);
            if ($badge === null) {
                return null;
            }
        } elseif ($event->type === FeedEventType::HealthMilestone) {
            $milestone = HealthMilestone::tryFrom($detail);
            if ($milestone === null || (!$mine && !$settings[$event->userId->value]->milestoneSharing->shows($milestone))) {
                return null;
            }
        }
        // Only a duel has an opponent: another user on a damaged row of any other type is not shown.
        // Named only to the opponent and to friends of the opponent: the user and the friends are
        // the profiles there are.
        $duel = $event->type === FeedEventType::DuelWon || $event->type === FeedEventType::DuelDraw;
        $opponent = !$duel || $event->otherUserId === null ? null : $profiles[$event->otherUserId->value] ?? null;

        return new FeedEntry($event->id, $event->type, $user, $mine, $level, $badge, $milestone, $opponent);
    }

    /**
     * @return int|null The level, or null for anything but a positive whole number (written by a later version).
     */
    private static function levelOf(string $detail): ?int
    {
        // Nine digits at most: no level comes near it, and the number fits an int.
        return preg_match('/\A[1-9][0-9]{0,8}\z/', $detail) === 1 ? (int) $detail : null;
    }
}
