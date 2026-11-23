<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Console;

use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Console\FinalizeDaysCommand;
use SmokeDetector\Domain\Xp\XpSource;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\LoggingUseCaseTestCase;

/**
 * `days:finalize` (HLD §5.2): the step of the cron job that makes days final and calculates the
 * weekly baselines. It prints counts only, and fails when a user could not be served.
 */
final class FinalizeDaysCommandTest extends LoggingUseCaseTestCase
{
    public function test_days_finalize_says_how_many_days_and_baselines_it_did(): void
    {
        $anna = $this->givenUser();
        $this->givenOnboarded($anna, '2026-11-09', '2026-11-12');
        $this->givenProgress($anna, '2026-11-12', baselineDay: '2026-11-16');
        $command = new FinalizeDaysCommand($this->finalizeDays());
        $output = new BufferedOutput();

        // Noon on the 17th: the 12th, 13th and 14th are due, and the baseline of Monday the 16th.
        $exitCode = $command->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['Finalized 3 days, calculated 1 baseline.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertSame('days:finalize', $command->name());
        self::assertNotSame('', $command->description());

        // It can run as often as wanted.
        $again = new BufferedOutput();
        self::assertSame(0, $command->run([], $again));
        self::assertSame(['Finalized 0 days, calculated 0 baselines.'], $again->lines);
    }

    public function test_days_finalize_says_when_it_ran_out_of_time_which_is_no_failure(): void
    {
        $anna = $this->givenUser();
        $this->givenOnboarded($anna, '2026-11-10');
        $this->progressRecords->afterSave = fn () => $this->clock->advanceSeconds(20);
        $output = new BufferedOutput();

        $exitCode = (new FinalizeDaysCommand($this->finalizeDays()))->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['Finalized 1 day, calculated 0 baselines. Out of time: the rest follows in the next run.'], $output->lines);
        self::assertSame([], $output->errors);
    }

    public function test_days_finalize_fails_when_a_user_could_not_be_served_and_still_serves_the_others(): void
    {
        $anna = $this->givenUser();
        $bob = $this->givenUser('bob@example.org');
        foreach ([$anna, $bob] as $user) {
            $this->givenOnboarded($user, '2026-11-14');
            $this->givenDay($user, '2026-11-14', 0, '2026-11-14 21:00:00');
        }
        // The id the first XP event of the run will get is taken: Anna's day cannot be stored.
        $this->xpLedger->append(new XpEvent(SequentialIdGenerator::id(3), $bob->id, self::date('2026-11-01'), XpSource::Reaction, 2, null, $this->now()));
        $output = new BufferedOutput();

        $exitCode = (new FinalizeDaysCommand($this->finalizeDays()))->run([], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['Finalized 1 day, calculated 0 baselines.'], $output->lines);
        self::assertSame(['Could not finalize the days of 1 user: see the log.'], $output->errors);
        // Nothing that was printed says whose days they were.
        self::assertStringNotContainsString($anna->id->value, implode(' ', [...$output->lines, ...$output->errors]));
        self::assertStringNotContainsString('anna@example.org', implode(' ', [...$output->lines, ...$output->errors]));
    }

    public function test_days_finalize_takes_no_arguments(): void
    {
        $anna = $this->givenUser();
        $this->givenOnboarded($anna, '2026-11-10');
        $output = new BufferedOutput();

        $exitCode = (new FinalizeDaysCommand($this->finalizeDays()))->run(['--dry-run'], $output);

        self::assertSame(1, $exitCode);
        self::assertSame(['days:finalize takes no arguments.'], $output->errors);
        self::assertSame([], $this->dayRecords->ofUser($anna->id));
        self::assertSame(TestData::date('2026-11-10')->value, $this->progressRecords->find($anna->id)?->nextDay->value);
    }
}
