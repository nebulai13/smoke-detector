<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\TransactionRunner;

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
 * FeedVisibility holds these rules, the same for reacting (React).
 *
 * Each entry has the user's own reaction to it, and an own entry who reacted, of those who are friends
 * now (HLD §4.13 "Who sees a reaction").
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
        private Reactions $reactions,
    ) {
    }

    /**
     * @return list<FeedEntry> At most LIMIT, the newest first.
     */
    public function show(UserId $userId): array
    {
        return $this->transactions->run(function () use ($userId): array {
            $visibility = FeedVisibility::of($userId, $this->users, $this->links, $this->settings);

            return $this->withReactions($visibility, $this->visibleEntries($visibility));
        });
    }

    /**
     * @return list<FeedEntry> At most LIMIT, the newest first, without reactions.
     */
    private function visibleEntries(FeedVisibility $visibility): array
    {
        $entries = [];
        for ($skip = 0; ; $skip += self::READ) {
            $events = $this->events->newestOf($visibility->subjects(), self::READ, $skip);
            foreach ($events as $event) {
                $entry = $visibility->entryOf($event);
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
    }

    /**
     * The reactions of the entries, read at once for all of them.
     *
     * @param list<FeedEntry> $entries
     *
     * @return list<FeedEntry>
     */
    private function withReactions(FeedVisibility $visibility, array $entries): array
    {
        $byEvent = $this->reactions->ofEvents(array_map(static fn (FeedEntry $entry): string => $entry->id, $entries));

        return array_map(static function (FeedEntry $entry) use ($visibility, $byEvent): FeedEntry {
            $mine = null;
            $views = [];
            foreach ($byEvent[$entry->id] ?? [] as $reaction) {
                if ($reaction->userId->equals($visibility->viewer)) {
                    $mine = $reaction->key;
                }
                // Who reacted is for the owner of the entry alone, and only of the friends of now.
                // The owner is not among them: nobody reacts to an own entry (React).
                $user = $entry->mine ? $visibility->profile($reaction->userId) : null;
                if ($user !== null) {
                    $views[] = new ReactionView($user, $reaction->key);
                }
            }

            return $entry->withReactions($mine, $views);
        }, $entries);
    }
}
