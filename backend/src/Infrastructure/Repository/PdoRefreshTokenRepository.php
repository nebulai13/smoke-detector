<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The sessions with their refresh tokens in the `refresh_tokens` table: one row per login.
 */
final readonly class PdoRefreshTokenRepository implements RefreshTokenRepository
{
    private const string SELECT = 'SELECT id, user_id, key_hash, token_hash, device_label, created_at, rotated_at, expires_at, '
        . 'revoked_at, generation FROM refresh_tokens';

    public function __construct(private Sql $sql)
    {
    }

    public function add(RefreshToken $session): void
    {
        $this->sql->execute(
            'INSERT INTO refresh_tokens (id, user_id, key_hash, token_hash, device_label, created_at, rotated_at, expires_at, '
            . 'revoked_at, generation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $session->id,
                $session->userId->value,
                $session->keyHash,
                $session->tokenHash,
                $session->deviceLabel?->value,
                Row::sqlTime($session->createdAt),
                Row::nullableSqlTime($session->rotatedAt),
                Row::sqlTime($session->expiresAt),
                Row::nullableSqlTime($session->revokedAt),
                $session->generation,
            ],
        );
    }

    public function findByKeyHash(#[SensitiveParameter] string $keyHash): ?RefreshToken
    {
        return self::session($this->sql->row(self::SELECT . ' WHERE key_hash = ?', [$keyHash]));
    }

    public function lock(string $id): ?RefreshToken
    {
        return self::session($this->sql->row(self::SELECT . ' WHERE id = ? FOR UPDATE', [$id]));
    }

    public function rotate(string $id, #[SensitiveParameter] string $tokenHash, int $generation, DateTimeImmutable $at, DateTimeImmutable $expiresAt): void
    {
        $this->sql->execute(
            'UPDATE refresh_tokens SET token_hash = ?, generation = ?, rotated_at = ?, expires_at = ? WHERE id = ?',
            [$tokenHash, $generation, Row::sqlTime($at), Row::sqlTime($expiresAt), $id],
        );
    }

    public function revoke(string $id, DateTimeImmutable $at): void
    {
        $this->sql->execute(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [Row::sqlTime($at), $id],
        );
    }

    public function revokeAllOfUser(UserId $userId, DateTimeImmutable $at): void
    {
        $this->sql->execute(
            'UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [Row::sqlTime($at), $userId->value],
        );
    }

    public function deleteAllButLastUsedOfUser(UserId $userId, int $keep): int
    {
        // Read first, then deleted by primary key: the rows are few (the caller holds the lock on
        // the account, so nobody adds one in between), and a DELETE by primary key of a row that
        // exists locks that row and nothing around it.
        $surplus = $this->sql->column(
            'SELECT id FROM refresh_tokens WHERE user_id = ? AND revoked_at IS NULL '
            . 'ORDER BY COALESCE(rotated_at, created_at) DESC, id DESC '
            . 'LIMIT 1000 OFFSET ?',
            [$userId->value, max(0, $keep)],
        );

        $deleted = 0;
        foreach ($surplus as $id) {
            $deleted += $this->sql->execute('DELETE FROM refresh_tokens WHERE id = ?', [is_string($id) ? $id : '']);
        }

        return $deleted;
    }

    public function deleteExpiredAndRevoked(DateTimeImmutable $now): int
    {
        return $this->sql->execute(
            'DELETE FROM refresh_tokens WHERE expires_at <= ? OR revoked_at IS NOT NULL',
            [Row::sqlTime($now)],
        );
    }

    /**
     * @param array<string, mixed>|null $columns
     */
    private static function session(?array $columns): ?RefreshToken
    {
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);
        $deviceLabel = $row->nullableString('device_label');

        return new RefreshToken(
            $row->string('id'),
            UserId::fromString($row->string('user_id')),
            $row->string('key_hash'),
            $row->string('token_hash'),
            $deviceLabel === null ? null : DeviceLabel::fromInput($deviceLabel),
            $row->time('created_at'),
            $row->nullableTime('rotated_at'),
            $row->time('expires_at'),
            $row->nullableTime('revoked_at'),
            $row->int('generation'),
        );
    }
}
