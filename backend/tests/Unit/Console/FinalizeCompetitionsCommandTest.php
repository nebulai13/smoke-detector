<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Console;

use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Console\FinalizeCompetitionsCommand;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LeaguesUseCaseTestCase;

/**
 * `competitions:finalize` (HLD §5.2, §4.8, §4.9): the step of the cron job that lets proposals
 * expire, decides duels and ranks league results. It prints counts only, and fails when one of them
 * could not be done.
 */
final class FinalizeCompetitionsCommandTest extends LeaguesUseCaseTestCase
{
    public function test_competitions_finalize_says_how_many_proposals_expired_and_how_many_duels_it_decided(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $carol = $this->givenNamed('Carol');
        $this->givenOnboarded($carol, '2026-11-01', '2026-11-15');
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenProposal(2, $carol, $anna, 7, '2026-11-01 08:00:00');
        $this->givenProposal(3, $bob, $carol, 7, '2026-11-01 08:00:00');
        $command = new FinalizeCompetitionsCommand($this->decideDuels(), $this->rankLeagues());
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['Expired 2 duel proposals, decided 1 duel, ranked 0 league results.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertSame('competitions:finalize', $command->name());
        self::assertNotSame('', $command->description());

        // It can run as often as wanted.
        $again = new BufferedOutput();
        self::assertSame(0, $command->run([], $again));
        self::assertSame(['Expired 0 duel proposals, decided 0 duels, ranked 0 league results.'], $again->lines);
    }

    public function test_competitions_finalize_counts_in_the_singular_and_in_the_plural(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $carol = $this->givenNamed('Carol');
        $this->givenOnboarded($carol, '2026-11-01', '2026-11-15');
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $this->givenRunning(2, $anna, $carol, '2026-11-03');
        $this->givenProposal(3, $bob, $carol, 7, '2026-11-01 08:00:00');
        // A result of somebody without friends: ranked at once.
        $this->givenResult($this->givenPlayer('Loner'), 42);
        $output = new BufferedOutput();

        (new FinalizeCompetitionsCommand($this->decideDuels(), $this->rankLeagues()))->run([], $output);

        self::assertSame(['Expired 1 duel proposal, decided 2 duels, ranked 1 league result.'], $output->lines);
    }

    public function test_competitions_finalize_fails_when_a_duel_could_not_be_decided_and_still_decides_the_others(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Damaged data: Carol has progress and no plan.
        $carol = $this->givenNamed('Carol');
        $this->progressRecords->save(new ProgressRecord(
            $carol->id, UserProgress::initial(), 0, self::date('2026-11-15'), TestData::time('2026-11-17 23:00:00'), TestData::time('2026-11-22 23:00:00'),
        ));
        $damaged = $this->givenRunning(1, $anna, $carol, '2026-11-03');
        $this->givenRunning(2, $anna, $bob, '2026-11-04');
        $output = new BufferedOutput();

        $exitCode = (new FinalizeCompetitionsCommand($this->decideDuels(), $this->rankLeagues()))->run([], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['Expired 0 duel proposals, decided 1 duel, ranked 0 league results.'], $output->lines);
        self::assertSame(['Could not decide 1 duel: see the log.'], $output->errors);
        // Nothing that was printed says whose duel it was.
        foreach ([$anna->id->value, $carol->id->value, $damaged->id] as $id) {
            self::assertStringNotContainsString($id, implode(' ', [...$output->lines, ...$output->errors]));
        }
    }

    public function test_competitions_finalize_fails_when_a_league_result_could_not_be_ranked_and_still_does_the_rest(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        // Damaged data: a result of somebody without a plan and without progress, first of three.
        $ghost = $this->givenNamed('Ghost');
        $this->givenFriends($ghost, $anna);
        $this->givenFriends($ghost, $bob);
        $this->givenResult($ghost, 10, 0, null, '2026-W45', LeaguePeriod::Week, '2026-11-17 10:00:00');
        $this->givenResult($anna, 42, 0, null, '2026-W45');
        $this->givenResult($bob, 60, 0, null, '2026-W45');
        $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $output = new BufferedOutput();

        $exitCode = (new FinalizeCompetitionsCommand($this->decideDuels(), $this->rankLeagues()))->run([], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['Expired 0 duel proposals, decided 1 duel, ranked 2 league results.'], $output->lines);
        self::assertSame(['Could not rank 1 league result: see the log.'], $output->errors);
        self::assertStringNotContainsString($ghost->id->value, implode(' ', [...$output->lines, ...$output->errors]));
    }

    public function test_competitions_finalize_takes_no_arguments(): void
    {
        [$anna, $bob] = $this->givenTwoFriends();
        $running = $this->givenRunning(1, $anna, $bob, '2026-11-03');
        $output = new BufferedOutput();

        $exitCode = (new FinalizeCompetitionsCommand($this->decideDuels(), $this->rankLeagues()))->run(['--dry-run'], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['competitions:finalize takes no arguments.'], $output->errors);
        self::assertSame(DuelStatus::Active, $this->duels->find($running->id)?->status);
    }
}
