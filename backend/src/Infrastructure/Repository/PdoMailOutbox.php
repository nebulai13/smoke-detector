<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The mail outbox in the `mail_outbox` table.
 */
final readonly class PdoMailOutbox implements MailOutbox
{
    public function __construct(private Sql $sql)
    {
    }

    public function add(QueuedMail $mail): void
    {
        $this->sql->execute(
            'INSERT INTO mail_outbox (id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $mail->id,
                $mail->userId->value,
                $mail->kind->value,
                $mail->locale->value,
                $mail->to->value,
                $mail->sealedValues,
                Row::sqlTime($mail->createdAt),
                Row::sqlTime($mail->notBefore),
                $mail->attempts,
            ],
        );
    }

    public function claimDue(DateTimeImmutable $now, int $limit, DateTimeImmutable $leaseUntil): array
    {
        // Only the templates and languages this release knows. After a rollback to an older
        // release the mails of the newer one stay where they are: they neither stop the sender
        // nor get lost.
        $kinds = array_map(static fn (MailKind $kind): string => $kind->value, MailKind::cases());
        $locales = array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());

        // SKIP LOCKED: rows another run is taking right now are passed over, not waited for. The
        // locks last until the caller's transaction ends, and by then the rows are not due any more.
        $rows = $this->sql->rows(
            'SELECT id, user_id, template, locale, recipient, sealed_values, created_at, not_before, attempts '
            . 'FROM mail_outbox WHERE not_before <= ? '
            . 'AND template IN (' . self::placeholders(count($kinds)) . ') '
            . 'AND locale IN (' . self::placeholders(count($locales)) . ') '
            . 'ORDER BY created_at, id LIMIT ? FOR UPDATE SKIP LOCKED',
            [Row::sqlTime($now), ...$kinds, ...$locales, $limit],
        );

        $mails = [];
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $mails[] = new QueuedMail(
                $row->string('id'),
                UserId::fromString($row->string('user_id')),
                MailKind::from($row->string('template')),
                Locale::from($row->string('locale')),
                EmailAddress::fromInput($row->string('recipient')),
                $row->string('sealed_values'),
                $row->time('created_at'),
                $row->time('not_before'),
                $row->int('attempts'),
            );
        }
        if ($mails === []) {
            return [];
        }

        // One statement per mail, by primary key equality: it touches only the row locked above.
        // A single `WHERE id IN (…)` is not safe: on a small table MariaDB runs it as a scan over
        // the primary key, which waits at every row another run holds, and two runs then deadlock.
        $leaseUntilSql = Row::sqlTime($leaseUntil);
        foreach ($mails as $mail) {
            $this->sql->execute('UPDATE mail_outbox SET not_before = ? WHERE id = ?', [$leaseUntilSql, $mail->id]);
        }

        return $mails;
    }

    public function reschedule(string $id, int $attempts, DateTimeImmutable $notBefore): void
    {
        $this->sql->execute(
            'UPDATE mail_outbox SET attempts = ?, not_before = ? WHERE id = ?',
            [$attempts, Row::sqlTime($notBefore), $id],
        );
    }

    public function delete(string $id): void
    {
        // By primary key: a row that exists is locked alone, nothing around it.
        $this->sql->execute('DELETE FROM mail_outbox WHERE id = ?', [$id]);
    }

    public function deleteQueuedBefore(DateTimeImmutable $moment): int
    {
        // The table holds the mails of the last minutes and has no index on created_at (see the
        // migration): reading it whole costs nothing.
        return $this->sql->execute('DELETE FROM mail_outbox WHERE created_at < ?', [Row::sqlTime($moment)]);
    }

    /**
     * "?, ?, ?" for a list of $count values. Question marks only: the values are bound.
     */
    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
