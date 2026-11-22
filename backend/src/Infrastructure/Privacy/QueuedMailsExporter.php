<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The `mail_outbox` table in the data export (schema ExportedQueuedMail): which mails to the user
 * wait to be sent, since when, and how often the mail server has refused them. Usually none: a
 * mail leaves within a minute or two.
 *
 * The encrypted links are not selected at all, so they cannot get into the document, and neither
 * is the address: it is the account's, which the profile shows.
 */
final readonly class QueuedMailsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        $mails = [];
        $rows = $this->sql->rows(
            'SELECT template, created_at, attempts FROM mail_outbox WHERE user_id = ? ORDER BY created_at, id',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $mails[] = [
                'template' => $row->string('template'),
                'createdAt' => ExportFormat::time($row->time('created_at')),
                'attempts' => $row->int('attempts'),
            ];
        }

        return $mails;
    }
}
