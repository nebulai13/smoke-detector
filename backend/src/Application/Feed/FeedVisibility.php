<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Settings\Settings;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * What one user may see of the feed (HLD §4.12 "What a user sees"): the one rule for showing the feed
 * (ShowFeed) and for reacting to an entry of it (React), so the two cannot disagree.
 *
 * - Friends only: an open request, a removed friend and a blocked user are not in it. An entry whose
 *   account is gone is left out.
 * - A health milestone of a friend is shown as far as that friend's setting allows now; the own ones
 *   always.
 * - The opponent of a duel is named only to that user and to friends of that user.
 * - An entry with a value this version of the code does not know (after a roll-back) is left out.
 */
final readonly class FeedVisibility
{
    /**
     * @param list<UserId>                 $subjects The viewer and the accepted friends.
     * @param array<string, PublicProfile> $profiles Of those whose account exists, by id.
     * @param array<string, Settings>      $settings Of every friend, by id.
     */
    private function __construct(
        public UserId $viewer,
        private array $subjects,
        private array $profiles,
        private array $settings,
    ) {
    }

    /**
     * Reads what the viewer may see now: the friends, their accounts and their settings.
     */
    public static function of(UserId $viewer, UserRepository $users, FriendLinks $links, UserSettings $settings): self
    {
        $friendIds = [];
        foreach ($links->ofUser($viewer) as $link) {
            if ($link->isAccepted()) {
                $friendIds[] = $link->other($viewer);
            }
        }
        // The accounts that exist, read once: whom an entry is about, and whom of the opponents
        // the viewer may see named, are among them.
        $profiles = [];
        foreach ([$viewer, ...$friendIds] as $id) {
            $account = $users->find($id);
            if ($account !== null) {
                $profiles[$id->value] = PublicProfile::of($account);
            }
        }

        // Each friend's own setting decides what of the milestones the viewer sees.
        return new self($viewer, [$viewer, ...$friendIds], $profiles, $settings->findAll($friendIds));
    }

    /**
     * @return list<UserId> Whose entries the feed is made of: the viewer and the accepted friends.
     */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /**
     * @return PublicProfile|null The viewer or an accepted friend of the viewer, whose account exists;
     *                            null for anybody else.
     */
    public function profile(UserId $userId): ?PublicProfile
    {
        return $this->profiles[$userId->value] ?? null;
    }

    /**
     * @return FeedEntry|null The entry as the viewer sees it, without reactions; null for an entry the
     *                        viewer may not see or this code cannot read.
     */
    public function entryOf(FeedEvent $event): ?FeedEntry
    {
        $user = $this->profiles[$event->userId->value] ?? null;
        if ($user === null) {
            return null;
        }
        $mine = $event->userId->equals($this->viewer);
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
            if ($milestone === null || (!$mine && !$this->settings[$event->userId->value]->milestoneSharing->shows($milestone))) {
                return null;
            }
        }
        // Only a duel has an opponent: another user on a damaged row of any other type is not shown.
        // Named only to the opponent and to friends of the opponent: the viewer and the friends are
        // the profiles there are.
        $duel = $event->type === FeedEventType::DuelWon || $event->type === FeedEventType::DuelDraw;
        $opponent = !$duel || $event->otherUserId === null ? null : $this->profiles[$event->otherUserId->value] ?? null;

        return new FeedEntry($event->id, $event->type, $user, $mine, $level, $badge, $milestone, $opponent, null, []);
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
