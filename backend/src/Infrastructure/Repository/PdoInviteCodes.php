<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use PDOException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodes;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The invite codes in the `invite_codes` table.
 */
final readonly class PdoInviteCodes implements InviteCodes
{
    /** MariaDB: a value of a unique key is taken already. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private Sql $sql)
    {
    }

    public function ofUser(UserId $userId): ?InviteCode
    {
        $code = $this->sql->value('SELECT code FROM invite_codes WHERE user_id = ?', [$userId->value]);

        return $code === null ? null : self::code($code);
    }

    public function owner(InviteCode $code): ?UserId
    {
        $userId = $this->sql->value('SELECT user_id FROM invite_codes WHERE code = ?', [$code->value]);

        return $userId === null ? null : UserId::fromString((new Row(['user_id' => $userId]))->string('user_id'));
    }

    public function assign(UserId $userId, InviteCode $code, DateTimeImmutable $at): bool
    {
        // Not INSERT … ON DUPLICATE KEY UPDATE: on a code that is taken, that would update the row
        // of the other user. The unique key on `code` refuses it here instead.
        try {
            if ($this->ofUser($userId) === null) {
                $this->sql->execute(
                    'INSERT INTO invite_codes (user_id, code, created_at) VALUES (?, ?, ?)',
                    [$userId->value, $code->value, Row::sqlTime($at)],
                );
            } else {
                $this->sql->execute(
                    'UPDATE invite_codes SET code = ?, created_at = ? WHERE user_id = ?',
                    [$code->value, Row::sqlTime($at), $userId->value],
                );
            }
        } catch (PDOException $failure) {
            // Only this statement failed; the transaction around it goes on.
            if (($failure->errorInfo[1] ?? null) === self::DUPLICATE_KEY) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    private static function code(mixed $value): InviteCode
    {
        // Stored from an InviteCode, so it is one.
        return InviteCode::fromInput((new Row(['code' => $value]))->string('code'));
    }
}
