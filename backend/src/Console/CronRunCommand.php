<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Health\CronHeartbeat;
use Throwable;

/**
 * php bin/console cron:run: everything the cron job does, in one command (HLD §5.2, §8). The
 * hosting's cron calls the wrapper bin/cron every minute, and the wrapper calls this.
 *
 * It runs its steps one after the other (today: `mail:send`, `days:finalize`, then
 * `housekeeping:purge`). Each step stands for itself: one that fails does not keep the next from
 * running, because a mail server that is down must not stop the cleanup, and the other way round.
 * Then it prints ONE line that says what every step did, and fails (exit code 1) when a step failed.
 *
 * A run in which every step succeeded is recorded as the cron heartbeat, which GET /health reports.
 */
final readonly class CronRunCommand implements Command
{
    /** @var list<Command> */
    private array $steps;

    public function __construct(
        private CronHeartbeat $heartbeat,
        private ClockInterface $clock,
        Command ...$steps,
    ) {
        $this->steps = array_values($steps);
    }

    public function name(): string
    {
        return 'cron:run';
    }

    public function description(): string
    {
        return 'Runs what the cron job runs every minute: ' . implode(', ', array_map(
            static fn (Command $step): string => $step->name(),
            $this->steps,
        )) . '.';
    }

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $summary = [];
        $failed = false;
        foreach ($this->steps as $step) {
            $said = new CollectedOutput();
            try {
                $succeeded = $step->run([], $said) === 0;
                $result = implode(' ', [...$said->lines(), ...$said->errors()]);
            } catch (Throwable $failure) {
                // The class only. The message of a failure may hold what must not be printed or
                // logged: an address, a value of a row.
                $succeeded = false;
                $result = $failure::class;
            }

            $summary[] = $step->name() . ': ' . ($succeeded ? $result : "FAILED ({$result})");
            $failed = $failed || !$succeeded;
        }

        if (!$failed) {
            try {
                $this->heartbeat->beat($this->clock->now());
            } catch (Throwable $failure) {
                $summary[] = 'heartbeat: FAILED (' . $failure::class . ')';
                $failed = true;
            }
        }

        $output->line(implode(' | ', $summary));

        return $failed ? 1 : 0;
    }
}
