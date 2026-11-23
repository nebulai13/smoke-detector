<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Console;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Health\CronHeartbeat;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\SendQueuedMails;
use SmokeDetector\Console\Command;
use SmokeDetector\Console\CronRunCommand;
use SmokeDetector\Console\MailSendCommand;
use SmokeDetector\Console\Output;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Fake\FakeMailValuesCipher;
use SmokeDetector\Tests\Support\Fake\ListingMailTemplates;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use SmokeDetector\Tests\Support\Fake\ScriptedMailer;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;
use Throwable;

/**
 * The commands of the cron job (HLD §5.2, §8): `mail:send` sends the mail outbox, and `cron:run`
 * is what the cron wrapper calls every minute.
 */
final class CronCommandsTest extends TestCase
{
    private FrozenClock $clock;

    /** @var ArrayObject<int, DateTimeImmutable> The heartbeats that were recorded, in order. */
    private ArrayObject $beats;

    protected function setUp(): void
    {
        $this->clock = FrozenClock::at('2026-11-17 12:00:00');
        $this->beats = new ArrayObject();
    }

    public function test_mail_send_sends_the_outbox_and_reports_counts_only(): void
    {
        $transactions = new InMemoryTransactionRunner();
        $outbox = new InMemoryMailOutbox($transactions);
        $cipher = new FakeMailValuesCipher();
        $mailer = new ScriptedMailer($this->clock, $transactions);
        $sealed = $cipher->seal(['confirmUrl' => 'https://app.example.test/verify-email?token=SECRET-TOKEN']);
        $transactions->run(function () use ($outbox, $sealed): void {
            $outbox->add(TestData::queuedMail(10, 1, sealedValues: $sealed));
            $outbox->add(TestData::queuedMail(11, 2, sealedValues: $sealed));
            $outbox->add(TestData::queuedMail(12, 3, sealedValues: $sealed, createdAt: '2026-11-17 10:00:00', notBefore: '2026-11-17 12:00:00', attempts: 4));
        });
        $mailer->failures = ['user2@example.org' => null, 'user3@example.org' => null];
        $command = new MailSendCommand(new SendQueuedMails($transactions, $this->clock, $outbox, $cipher, new ListingMailTemplates(), $mailer, new RecordingLogger()));
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        // A mail the server did not take is not a failed run: it is kept and tried again.
        self::assertSame(0, $exitCode);
        self::assertSame(['Sent 1 mail, kept 2 mails for another try, gave up 0 mails.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertSame('mail:send', $command->name());
        self::assertNotSame('', $command->description());

        // It can run as often as wanted.
        $again = new BufferedOutput();
        self::assertSame(0, $command->run([], $again));
        self::assertSame(['Sent 0 mails, kept 0 mails for another try, gave up 0 mails.'], $again->lines);
    }

    public function test_mail_send_fails_when_a_mail_could_not_be_made_and_still_sends_the_others(): void
    {
        $transactions = new InMemoryTransactionRunner();
        $outbox = new InMemoryMailOutbox($transactions);
        $cipher = new FakeMailValuesCipher();
        $mailer = new ScriptedMailer($this->clock, $transactions);
        $templates = new ListingMailTemplates();
        $templates->broken = ['reset_password'];
        $sealed = $cipher->seal(['url' => 'https://app.example.test/x?token=SECRET-TOKEN']);
        $transactions->run(function () use ($outbox, $sealed): void {
            $outbox->add(TestData::queuedMail(10, 1, MailKind::ResetPassword, $sealed, '2026-11-17 11:58:00'));
            $outbox->add(TestData::queuedMail(11, 2, MailKind::ResetPassword, $sealed, '2026-11-17 11:59:00'));
            $outbox->add(TestData::queuedMail(12, 3, MailKind::ConfirmEmail, $sealed));
        });
        $command = new MailSendCommand(new SendQueuedMails($transactions, $this->clock, $outbox, $cipher, $templates, $mailer, new RecordingLogger()));
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        // A broken release: the cron job must say FAILED and write no heartbeat (cron:run does
        // that for a step that exits with 1).
        self::assertSame(1, $exitCode);
        self::assertSame(['Sent 1 mail, kept 0 mails for another try, gave up 0 mails.'], $output->lines);
        self::assertSame(['Could not make 2 mails: see the log.'], $output->errors);
        self::assertStringNotContainsString('SECRET-TOKEN', implode(' ', [...$output->lines, ...$output->errors]));
    }

    public function test_mail_send_takes_no_arguments(): void
    {
        $transactions = new InMemoryTransactionRunner();
        $outbox = new InMemoryMailOutbox($transactions);
        $cipher = new FakeMailValuesCipher();
        $transactions->run(fn () => $outbox->add(TestData::queuedMail(10, 1, sealedValues: $cipher->seal([]))));
        $mailer = new ScriptedMailer($this->clock, $transactions);
        $command = new MailSendCommand(new SendQueuedMails($transactions, $this->clock, $outbox, $cipher, new ListingMailTemplates(), $mailer, new RecordingLogger()));
        $output = new BufferedOutput();

        // "mail:send --dry-run" must not send for real because the option was ignored.
        self::assertSame(1, $command->run(['--dry-run'], $output));

        self::assertSame(['mail:send takes no arguments.'], $output->errors);
        self::assertSame([], $mailer->attempts);
    }

    public function test_cron_run_runs_its_steps_in_order_prints_one_line_and_records_the_heartbeat(): void
    {
        $order = new ArrayObject();
        $command = $this->cronRun(
            self::step('mail:send', 0, ['Sent 2 mails, kept 0 mails for another try, gave up 0 mails.'], order: $order),
            self::step('housekeeping:purge', 0, ['Deleted 1 unconfirmed registration.'], order: $order),
        );
        $this->clock->advanceSeconds(7);
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['mail:send', 'housekeeping:purge'], $order->getArrayCopy());
        self::assertSame(
            ['mail:send: Sent 2 mails, kept 0 mails for another try, gave up 0 mails. | housekeeping:purge: Deleted 1 unconfirmed registration.'],
            $output->lines,
        );
        self::assertSame([], $output->errors);
        self::assertEquals([TestData::time('2026-11-17 12:00:07')], $this->beats->getArrayCopy());
        self::assertSame('cron:run', $command->name());
        self::assertNotSame('', $command->description());
    }

    public function test_the_heartbeat_is_the_time_the_run_ended(): void
    {
        $slow = self::step('mail:send', 0, ['Sent 9 mails.'], during: function (): void {
            $this->clock->advanceSeconds(38);
        });

        $this->cronRun($slow)->run([], new BufferedOutput());

        self::assertEquals([TestData::time('2026-11-17 12:00:38')], $this->beats->getArrayCopy());
    }

    public function test_a_step_that_throws_does_not_stop_the_next_and_the_run_fails_without_a_heartbeat(): void
    {
        $order = new ArrayObject();
        $command = $this->cronRun(
            self::step('mail:send', 0, [], new RuntimeException('Cannot send to anna@example.org: SECRET'), order: $order),
            self::step('housekeeping:purge', 0, ['Deleted 3 unconfirmed registrations.'], order: $order),
        );
        $output = new BufferedOutput();

        $exitCode = $command->run([], $output);

        // The cleanup of unconfirmed registrations must not depend on the mail server, and the
        // other way round.
        self::assertSame(['mail:send', 'housekeeping:purge'], $order->getArrayCopy());
        self::assertSame(1, $exitCode);
        // One line, and of the failure only its class: the message may hold an address.
        self::assertSame(['mail:send: FAILED (RuntimeException) | housekeeping:purge: Deleted 3 unconfirmed registrations.'], $output->lines);
        self::assertSame([], $output->errors);
        self::assertCount(0, $this->beats);
    }

    public function test_a_failure_of_the_last_step_fails_the_run_too(): void
    {
        $command = $this->cronRun(
            self::step('mail:send', 0, ['Sent 0 mails.']),
            self::step('housekeeping:purge', 0, [], new RuntimeException('Database gone.')),
        );
        $output = new BufferedOutput();

        self::assertSame(1, $command->run([], $output));
        self::assertSame(['mail:send: Sent 0 mails. | housekeeping:purge: FAILED (RuntimeException)'], $output->lines);
        self::assertCount(0, $this->beats);
    }

    public function test_a_step_that_reports_failure_by_its_exit_code_fails_the_run_with_what_it_said(): void
    {
        $command = $this->cronRun(
            self::step('mail:send', 2, ['Half done.'], errors: ['mail:send is not set up.']),
            self::step('housekeeping:purge', 0, ['Deleted nothing.']),
        );
        $output = new BufferedOutput();

        self::assertSame(1, $command->run([], $output));
        self::assertSame(['mail:send: FAILED (Half done. mail:send is not set up.) | housekeeping:purge: Deleted nothing.'], $output->lines);
        self::assertCount(0, $this->beats);
    }

    public function test_a_heartbeat_that_cannot_be_recorded_fails_the_run_after_all_steps_ran(): void
    {
        $command = new CronRunCommand($this->heartbeat(new RuntimeException('The cron heartbeat could not be written.')), $this->clock, self::step('mail:send', 0, ['Sent 0 mails.']));
        $output = new BufferedOutput();

        self::assertSame(1, $command->run([], $output));
        self::assertSame(['mail:send: Sent 0 mails. | heartbeat: FAILED (RuntimeException)'], $output->lines);
    }

    public function test_cron_run_takes_no_arguments(): void
    {
        $order = new ArrayObject();
        $command = $this->cronRun(self::step('mail:send', 0, ['Sent 0 mails.'], order: $order));
        $output = new BufferedOutput();

        self::assertSame(1, $command->run(['--now', '2026-11-17'], $output));

        self::assertSame(['cron:run takes no arguments.'], $output->errors);
        self::assertCount(0, $order);
        self::assertCount(0, $this->beats);
    }

    private function cronRun(Command ...$steps): CronRunCommand
    {
        return new CronRunCommand($this->heartbeat(null), $this->clock, ...$steps);
    }

    private function heartbeat(?Throwable $failure): CronHeartbeat
    {
        return new readonly class ($this->beats, $failure) implements CronHeartbeat {
            /**
             * @param ArrayObject<int, DateTimeImmutable> $beats
             */
            public function __construct(private ArrayObject $beats, private ?Throwable $failure)
            {
            }

            public function beat(DateTimeImmutable $at): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $this->beats->append($at);
            }

            public function lastBeat(): ?DateTimeImmutable
            {
                $beats = $this->beats->getArrayCopy();

                return $beats === [] ? null : $beats[array_key_last($beats)];
            }
        };
    }

    /**
     * A step of the cron job that prints what it is told to and ends as it is told to.
     *
     * @param list<string>                  $lines
     * @param list<string>                  $errors
     * @param ArrayObject<int, string>|null $order  Gets the name of the step when it runs.
     * @param (Closure(): void)|null        $during
     */
    private static function step(
        string $name,
        int $exitCode,
        array $lines,
        ?Throwable $failure = null,
        array $errors = [],
        ?ArrayObject $order = null,
        ?Closure $during = null,
    ): Command {
        return new readonly class ($name, $exitCode, $lines, $failure, $errors, $order, $during) implements Command {
            /**
             * @param list<string>                  $lines
             * @param list<string>                  $errors
             * @param ArrayObject<int, string>|null $order
             * @param (Closure(): void)|null        $during
             */
            public function __construct(
                private string $name,
                private int $exitCode,
                private array $lines,
                private ?Throwable $failure,
                private array $errors,
                private ?ArrayObject $order,
                private ?Closure $during,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return "Describes {$this->name}.";
            }

            public function run(array $arguments, Output $output): int
            {
                $this->order?->append($this->name);
                if ($arguments !== []) {
                    throw new RuntimeException('A step of the cron job gets no arguments.');
                }
                if ($this->during !== null) {
                    ($this->during)();
                }
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                foreach ($this->lines as $line) {
                    $output->line($line);
                }
                foreach ($this->errors as $error) {
                    $output->error($error);
                }

                return $this->exitCode;
            }
        };
    }
}
