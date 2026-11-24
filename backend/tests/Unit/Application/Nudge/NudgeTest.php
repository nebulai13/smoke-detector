<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Nudge;

use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Feed\React;
use SmokeDetector\Application\Friends\FriendNotFound;
use SmokeDetector\Application\Friends\PublicProfile;
use SmokeDetector\Application\Nudge\AlreadyNudged;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\SendNudge;
use SmokeDetector\Application\Nudge\ShowNudges;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * `POST /friends/nudge` and `GET /nudges` (HLD §4.14; FR-SOC-03, FR-SOC-04): a user nudges a friend to
 * check in, once per friend and day. A nudge can always be sent. It is delivered only if the friend
 * has not checked in today, and the sender is told nothing either way (decided by the owner on
 * 2026-11-23): whether a friend has checked in today is the friend's own business until the day is final.
 *
 * It is noon UTC on Tuesday, 17 November 2026. Anna, Bob and Carol are friends of each other, live in
 * Vienna and have a plan.
 */
final class NudgeTest extends LeaguesUseCaseTestCase
{
    private const string NOW = '2026-11-17 12:00:00';

    private User $anna;
    private User $bob;
    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->anna, $this->bob] = $this->givenTwoFriends();
        $this->carol = $this->givenPlayer('Carol', '2026-11-01');
        $this->givenFriends($this->anna, $this->carol);
        $this->givenFriends($this->bob, $this->carol);
    }

    public function test_FR_SOC_03_a_nudge_to_a_friend_who_has_not_checked_in_today_is_delivered(): void
    {
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);

        // Stored on the sender's own day.
        self::assertEquals([new Nudge($this->anna->id, $this->bob->id, self::date('2026-11-17'), TestData::time(self::NOW), true)], $this->nudges->all());
        self::assertSame(['Anna'], $this->nudgesFor($this->bob));
        self::assertSame([], $this->nudgesFor($this->anna));
        self::assertSame([], $this->nudgesFor($this->carol));
    }

    public function test_FR_SOC_03_a_nudge_to_a_friend_who_has_checked_in_is_taken_and_never_delivered(): void
    {
        $this->givenDay($this->bob, '2026-11-17', 3, '2026-11-17 08:00:00');

        // The same for the sender: no error and no answer that says anything.
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);

        self::assertEquals([new Nudge($this->anna->id, $this->bob->id, self::date('2026-11-17'), TestData::time(self::NOW), false)], $this->nudges->all());
        self::assertSame([], $this->nudgesFor($this->bob));
    }

    public function test_FR_SOC_03_a_friend_without_a_plan_has_nothing_to_check_in(): void
    {
        $new = $this->givenNamed('New');
        $this->givenFriends($this->anna, $new);

        $this->sendNudge()->execute($this->anna->id, $new->id);

        self::assertFalse($this->nudges->all()[0]->delivered);
        self::assertSame([], $this->nudgesFor($new));
    }

    public function test_FR_SOC_03_one_nudge_per_friend_and_day_whether_it_was_delivered_or_not(): void
    {
        $this->givenDay($this->carol, '2026-11-17', 0, '2026-11-17 08:00:00');
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
        $this->sendNudge()->execute($this->anna->id, $this->carol->id);

        foreach ([$this->bob, $this->carol] as $friend) {
            try {
                $this->sendNudge()->execute($this->anna->id, $friend->id);
                self::fail('A second nudge to the same friend on the same day was taken.');
            } catch (AlreadyNudged) {
                // The same answer for both: it does not tell which of the two was delivered.
            }
        }
        self::assertCount(2, $this->nudges->all());
        // Bob can nudge back, and Anna's next day begins at 23:00 UTC.
        $this->sendNudge()->execute($this->bob->id, $this->anna->id);
        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
        self::assertEquals(
            new Nudge($this->anna->id, $this->bob->id, self::date('2026-11-18'), TestData::time('2026-11-17 23:00:00'), true),
            $this->nudges->find($this->anna->id, $this->bob->id, self::date('2026-11-18')),
        );
    }

    public function test_FR_SOC_03_the_day_of_the_check_in_is_the_friends_own_day(): void
    {
        // Carol lives in Auckland, where it is the 18th already. She checked in on her 17th, not yet on her 18th.
        $carol = $this->givenZoneChange($this->carol, '2026-11-01', 'Pacific/Auckland');
        $this->givenDay($carol, '2026-11-17', 0, '2026-11-17 08:00:00');
        $this->sendNudge()->execute($this->anna->id, $carol->id);
        self::assertTrue($this->nudges->all()[0]->delivered);

        // Bob lives in Vienna and checked in on the 18th, which has not begun there: that is no check-in of today.
        $this->givenDay($this->bob, '2026-11-18', 0, '2026-11-17 11:00:00');
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
        self::assertTrue($this->nudges->all()[1]->delivered);
    }

    public function test_FR_SOC_03_only_a_friend_can_be_nudged(): void
    {
        $stranger = $this->givenPlayer('Stranger');
        $asker = $this->givenPlayer('Asker');
        $asked = $this->givenPlayer('Asked');
        $this->givenRequest($asker, $this->anna);
        $this->givenRequest($this->anna, $asked);
        $this->friendList()->remove($this->anna->id, $this->carol->id);
        $this->blocking()->block($this->bob->id, $this->anna->id);
        $nobody = [
            'a stranger' => $stranger->id,
            'somebody who asked' => $asker->id,
            'somebody who was asked' => $asked->id,
            'a removed friend' => $this->carol->id,
            'somebody who blocked the user' => $this->bob->id,
            'the user' => $this->anna->id,
            'nobody' => TestData::userId(9999),
        ];

        foreach ($nobody as $case => $userId) {
            try {
                $this->sendNudge()->execute($this->anna->id, $userId);
                self::fail("A nudge to {$case} was taken.");
            } catch (FriendNotFound) {
                // The same answer for all of them: it does not tell whether a user id exists.
            }
        }
        self::assertSame([], $this->nudges->all());
    }

    public function test_FR_SOC_04_nudges_and_reactions_together_are_thirty_a_day(): void
    {
        $friends = [];
        for ($number = 1; $number <= 31; $number++) {
            $friends[] = $friend = $this->givenNamed("Friend {$number}");
            $this->givenFriends($this->anna, $friend);
        }
        foreach (array_slice($friends, 0, 30) as $friend) {
            $this->sendNudge()->execute($this->anna->id, $friend->id);
        }

        try {
            $this->sendNudge()->execute($this->anna->id, $friends[30]->id);
            self::fail('The thirty-first action of the day was taken.');
        } catch (RateLimitExceeded $refused) {
            self::assertSame(43_200, $refused->retryAfterSeconds);
        }
        self::assertCount(30, $this->nudges->all());
        self::assertSame(30, React::DAILY_ACTIONS);
        self::assertSame(30, SendNudge::DAILY_ACTIONS);
        // Bob has his own thirty.
        $this->sendNudge()->execute($this->bob->id, $this->anna->id);
    }

    public function test_FR_SOC_04_a_refused_nudge_is_one_of_the_thirty(): void
    {
        for ($try = 1; $try <= 30; $try++) {
            try {
                $this->sendNudge()->execute($this->anna->id, TestData::userId(9000 + $try));
                self::fail('A nudge to nobody was taken.');
            } catch (FriendNotFound) {
                // Counted: otherwise user ids could be tried without end.
            }
        }

        $this->expectException(RateLimitExceeded::class);

        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_nudges_nobody(): void
    {
        $this->users->delete($this->anna->id);

        try {
            $this->sendNudge()->execute($this->anna->id, $this->bob->id);
            self::fail('An account that is gone sent a nudge.');
        } catch (AccountNotFound) {
            self::assertSame([], $this->counters->all());
            self::assertSame([], $this->nudges->all());
        }
    }

    // ---- What the receiver sees -------------------------------------------------------------------

    public function test_FR_SOC_03_the_nudges_of_today_are_shown_oldest_first_until_the_user_checks_in(): void
    {
        $this->sendNudge()->execute($this->carol->id, $this->bob->id);
        $this->clock->advanceSeconds(60);
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);

        $nudges = $this->showNudges()->of($this->bob->id);
        self::assertSame(['Carol', 'Anna'], array_map(static fn (PublicProfile $from): string => $from->displayName->value, $nudges));
        self::assertSame([$this->carol->id->value, 'Carol', 'avatar-07'], self::profile($nudges[0]));

        // Bob checks in: the nudges did their work.
        $this->givenDay($this->bob, '2026-11-17', 2, '2026-11-17 12:05:00');
        self::assertSame([], $this->nudgesFor($this->bob));
    }

    public function test_FR_SOC_03_a_nudge_of_yesterday_is_not_shown_today(): void
    {
        // 22:59 UTC is still the 17th in Vienna. A minute later Bob's 18th begins.
        $this->clock->travelTo(TestData::time('2026-11-17 22:59:00'));
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
        self::assertSame(['Anna'], $this->nudgesFor($this->bob));

        $this->clock->travelTo(TestData::time('2026-11-17 23:00:00'));
        self::assertSame([], $this->nudgesFor($this->bob));
        // A nudge at the first moment of the day is one of the day.
        $this->sendNudge()->execute($this->carol->id, $this->bob->id);
        self::assertSame(['Carol'], $this->nudgesFor($this->bob));
    }

    public function test_FR_FRND_02_a_nudge_of_somebody_who_is_no_friend_any_more_is_not_shown(): void
    {
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);
        $this->sendNudge()->execute($this->carol->id, $this->bob->id);

        $this->blocking()->block($this->bob->id, $this->anna->id);
        $this->users->delete($this->carol->id);

        self::assertSame([], $this->nudgesFor($this->bob));
    }

    public function test_FR_FRND_02_a_nudge_of_somebody_who_is_no_friend_any_more_hides_none_of_the_others(): void
    {
        // Carol nudges first, Anna a minute later. Then Bob removes Carol.
        $this->sendNudge()->execute($this->carol->id, $this->bob->id);
        $this->clock->advanceSeconds(60);
        $this->sendNudge()->execute($this->anna->id, $this->bob->id);

        $this->friendList()->remove($this->bob->id, $this->carol->id);

        self::assertSame(['Anna'], $this->nudgesFor($this->bob));
    }

    public function test_FR_SOC_03_somebody_without_a_plan_has_no_nudges(): void
    {
        $new = $this->givenNamed('New');
        $this->givenFriends($this->anna, $new);
        // Stored by hand as delivered, which the application never does for somebody without a plan.
        $this->nudges->add(new Nudge($this->anna->id, $new->id, self::date('2026-11-17'), TestData::time(self::NOW), true));

        self::assertSame([], $this->nudgesFor($new));
    }

    private function sendNudge(): SendNudge
    {
        return new SendNudge(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->friendLinks,
            $this->nudges,
            new RateLimiter($this->counters, RateLimitKey::hasher()),
            $this->calendars(),
            $this->dayRecords,
        );
    }

    private function showNudges(): ShowNudges
    {
        return new ShowNudges($this->transactions, $this->clock, $this->users, $this->friendLinks, $this->nudges, $this->calendars(), $this->dayRecords);
    }

    /**
     * @return list<string> The names of those whose nudges the user sees now, oldest first.
     */
    private function nudgesFor(User $user): array
    {
        return array_map(static fn (PublicProfile $from): string => $from->displayName->value, $this->showNudges()->of($user->id));
    }
}
