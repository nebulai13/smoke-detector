<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Application\Mail\SendQueuedMails;

/**
 * php bin/console mail:send: sends the mails that wait in the outbox (see SendQueuedMails). No web
 * request sends mail itself, so without this command, run every minute by the cron job (HLD §8),
 * nobody gets a confirmation or reset link.
 *
 * It can run as often as wanted, also twice at the same time. A mail the mail server did not take
 * is kept and tried again by a later run: that is not a failure of the command. A mail that could
 * not be made (its template cannot be rendered) is: the release is broken and every such mail is
 * stuck, so the command exits with 1, and cron:run says FAILED and writes no heartbeat.
 */
final readonly class MailSendCommand implements Command
{
    public function __construct(private SendQueuedMails $sendQueuedMails)
    {
    }

    public function name(): string
    {
        return 'mail:send';
    }

    public function description(): string
    {
        return 'Sends the mails that wait in the outbox (confirmation and reset links).';
    }

    public function run(array $arguments, Output $output): int
    {
        // An ignored option such as --dry-run would send for real against the caller's intention.
        if ($arguments !== []) {
            $output->error($this->name() . ' takes no arguments.');

            return 1;
        }

        $report = $this->sendQueuedMails->execute();

        // Counts only: nothing here says whose mails they were.
        $output->line(sprintf(
            'Sent %s, kept %s for another try, gave up %s.',
            self::count($report->sent),
            self::count($report->retried),
            self::count($report->givenUp),
        ));

        if ($report->notMade > 0) {
            // Which mails and why is in the log, without address or link (SendQueuedMails).
            $output->error('Could not make ' . self::count($report->notMade) . ': see the log.');

            return 1;
        }

        return 0;
    }

    private static function count(int $number): string
    {
        return $number . ($number === 1 ? ' mail' : ' mails');
    }
}
