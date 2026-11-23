<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Application\Competition\EndDuels;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET /friends and POST /friends/remove (FR-FRND-02, FR-FRND-03, HLD §4.7): the friends of a user
 * with what friends see of each other, the open requests apart, and the end of a friendship.
 */
final readonly class FriendList
{
    public function __construct(
        private TransactionRunner $transactions,
        private UserRepository $users,
        private FriendLinks $links,
        private ProgressRecords $progress,
        private EndDuels $endDuels,
        private BadgeRecords $badges,
        private MilestoneRecords $milestones,
        private UserSettings $settings,
    ) {
    }

    /**
     * Friends by name without regard to capitals, then by user id; requests oldest first. A link
     * whose other account does not exist (any more) is left out. Locks nothing: one transaction
     * only so that everything is read from one snapshot.
     */
    public function show(UserId $userId): FriendOverview
    {
        return $this->transactions->run(function () use ($userId): FriendOverview {
            $friends = [];
            $incoming = [];
            $outgoing = [];
            $links = $this->links->ofUser($userId);
            // The badges, health milestones and settings of all friends in one read each. Somebody
            // with an open request is not asked for.
            $friendIds = [];
            foreach ($links as $link) {
                if ($link->acceptedAt !== null) {
                    $friendIds[] = $link->other($userId);
                }
            }
            $badges = $this->badges->heldBy($friendIds);
            $milestones = $this->milestones->reachedBy($friendIds);
            // Each friend's own setting decides what of the milestones the user sees.
            $settings = $this->settings->findAll($friendIds);
            foreach ($links as $link) {
                $otherId = $link->other($userId);
                $other = $this->users->find($otherId);
                if ($other === null) {
                    continue;
                }
                if ($link->acceptedAt !== null) {
                    // The stored progress, never the projection: a friend's open days are the friend's own business.
                    $friends[] = FriendView::of(
                        $other,
                        $link->acceptedAt,
                        $this->progress->find($otherId),
                        $badges[$otherId->value] ?? [],
                        $milestones[$otherId->value] ?? [],
                        $settings[$otherId->value]->milestoneSharing,
                    );
                } elseif ($link->requester->equals($userId)) {
                    $outgoing[] = new PendingRequest(PublicProfile::of($other), $link->requestedAt);
                } else {
                    $incoming[] = new PendingRequest(PublicProfile::of($other), $link->requestedAt);
                }
            }
            usort(
                $friends,
                static fn (FriendView $a, FriendView $b): int => PublicProfile::compareByName($a->user, $b->user),
            );

            return new FriendOverview($friends, $incoming, $outgoing);
        });
    }

    /**
     * Ends the friendship for both. An open request is not removed this way: it is declined or
     * taken back. The proposal between the two is deleted and their running duel cancelled
     * (HLD §4.8 "Friends").
     *
     * @throws FriendNotFound  The two are not friends, or the other account does not exist (any more).
     * @throws AccountNotFound When the account of $userId was deleted in the meantime.
     */
    public function remove(UserId $userId, UserId $friend): void
    {
        $this->transactions->run(function () use ($userId, $friend): void {
            $account = LockedPair::lock($this->users, $userId, $friend);
            if ($account === null || !($this->links->between($userId, $friend)?->isAccepted() ?? false)) {
                throw new FriendNotFound();
            }
            $this->links->delete($userId, $friend);
            $this->endDuels->between($userId, $friend);
        });
    }
}
