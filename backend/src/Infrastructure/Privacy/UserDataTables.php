<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\Privacy\UserDataTable;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * THE list of the tables that hold data of a user. The data export (FR-ACC-07) and account
 * deletion (FR-ACC-08) read it and nothing else.
 *
 * A build step that adds a table with user data adds one entry to registry():
 *
 *     new UserDataTable('smoke_logs', 'smokeLogs', new SmokeLogsExporter($this->sql), new RowsOfUser($this->sql, 'smoke_logs')),
 *
 * with
 * - the table name,
 * - the member of the export document (and its schema in AccountExport of api/openapi.yaml),
 * - an exporter: a small class next to this one that selects the user's rows and names the columns
 *   as the schema does (never a secret column),
 * - an eraser: RowsOfUser when every row belongs to one user alone, otherwise a class of its own
 *   (a duel or a friendship also belongs to the other user).
 *
 * The order is the order of erasure: a table comes before the tables its rows refer to.
 * UserDataGuardTest fails for a table that refers to users and is not listed here, and
 * UserDataTablesTest for a column that is neither exported nor deliberately left out.
 */
final readonly class UserDataTables
{
    public function __construct(private Sql $sql)
    {
    }

    public function registry(): UserDataRegistry
    {
        return new UserDataRegistry([
            new UserDataTable('consents', 'consents', new ConsentsExporter($this->sql), new RowsOfUser($this->sql, 'consents')),
            new UserDataTable('plans', 'plan', new PlanExporter($this->sql), new RowsOfUser($this->sql, 'plans')),
            new UserDataTable('baseline_history', 'baselineHistory', new BaselineHistoryExporter($this->sql), new RowsOfUser($this->sql, 'baseline_history')),
            new UserDataTable('refresh_tokens', 'sessions', new SessionsExporter($this->sql), new RowsOfUser($this->sql, 'refresh_tokens')),
            new UserDataTable('one_time_tokens', 'mailLinks', new MailLinksExporter($this->sql), new RowsOfUser($this->sql, 'one_time_tokens')),
            new UserDataTable('mail_outbox', 'queuedMails', new QueuedMailsExporter($this->sql), new RowsOfUser($this->sql, 'mail_outbox')),
        ]);
    }
}
