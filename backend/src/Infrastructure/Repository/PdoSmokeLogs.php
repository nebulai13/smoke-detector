<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The logs in the `smoke_logs` table.
 */
final readonly class PdoSmokeLogs implements SmokeLogs
{
    public function __construct(private Sql $sql)
    {
    }

    public function find(string $id): ?SmokeLog
    {
        $columns = $this->sql->row(
            'SELECT id, user_id, smoked_at, local_date, product_type, `count`, created_at, updated_at, deleted_at '
            . 'FROM smoke_logs WHERE id = ?',
            [$id],
        );
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return new SmokeLog(
            $row->string('id'),
            UserId::fromString($row->string('user_id')),
            $row->time('smoked_at'),
            LocalDate::fromString($row->string('local_date')),
            ProductType::from($row->string('product_type')),
            $row->int('count'),
            $row->time('created_at'),
            $row->time('updated_at'),
            $row->nullableTime('deleted_at'),
        );
    }

    public function save(SmokeLog $log): bool
    {
        // One statement, so two requests that send the same new id at the same moment cannot both
        // insert: the second waits for the first and then takes the UPDATE branch. That branch
        // changes the row only when it belongs to the same user (user_id itself is never assigned,
        // so the condition reads the stored owner in every assignment). created_at stays as first
        // stored.
        $this->sql->execute(
            'INSERT INTO smoke_logs (id, user_id, smoked_at, local_date, product_type, `count`, created_at, updated_at, deleted_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'smoked_at = IF(user_id = VALUES(user_id), VALUES(smoked_at), smoked_at), '
            . 'local_date = IF(user_id = VALUES(user_id), VALUES(local_date), local_date), '
            . 'product_type = IF(user_id = VALUES(user_id), VALUES(product_type), product_type), '
            . '`count` = IF(user_id = VALUES(user_id), VALUES(`count`), `count`), '
            . 'updated_at = IF(user_id = VALUES(user_id), VALUES(updated_at), updated_at), '
            . 'deleted_at = IF(user_id = VALUES(user_id), VALUES(deleted_at), deleted_at)',
            [
                $log->id,
                $log->userId->value,
                Row::sqlTime($log->smokedAt),
                $log->localDate->value,
                $log->productType->value,
                $log->count,
                Row::sqlTime($log->createdAt),
                Row::sqlTime($log->updatedAt),
                Row::nullableSqlTime($log->deletedAt),
            ],
        );

        // Whose the row is now. A row this transaction inserted or changed is always visible to it.
        // A row of another user may not be (when this transaction's snapshot is older than that
        // user's commit): then nothing is found, which also means "not this user's".
        return $this->sql->value('SELECT user_id FROM smoke_logs WHERE id = ?', [$log->id]) === $log->userId->value;
    }

    public function sessionsOn(UserId $userId, LocalDate $date): int
    {
        $sessions = $this->sql->value(
            'SELECT CAST(COALESCE(SUM(`count`), 0) AS UNSIGNED) FROM smoke_logs '
            . 'WHERE user_id = ? AND local_date = ? AND deleted_at IS NULL',
            [$userId->value, $date->value],
        );

        return (new Row(['sessions' => $sessions]))->int('sessions');
    }

    public function lastSessionAt(UserId $userId): ?DateTimeImmutable
    {
        $last = $this->sql->value('SELECT MAX(smoked_at) FROM smoke_logs WHERE user_id = ? AND deleted_at IS NULL', [$userId->value]);

        return (new Row(['last' => $last]))->nullableTime('last');
    }

    public function productsOf(UserId $userId): array
    {
        $used = $this->sql->column('SELECT DISTINCT product_type FROM smoke_logs WHERE user_id = ? AND deleted_at IS NULL', [$userId->value]);

        // In the order of the cases, whatever order the database finds them in.
        return array_values(array_filter(ProductType::cases(), static fn (ProductType $type): bool => in_array($type->value, $used, true)));
    }
}
