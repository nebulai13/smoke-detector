<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The tokens of mail links in the `one_time_tokens` table.
 */
final readonly class PdoOneTimeTokenRepository implements OneTimeTokenRepository
{
    private const string SELECT = 'SELECT id, user_id, purpose, token_hash, expires_at, used_at, failed_attempts FROM one_time_tokens '
        . 'WHERE token_hash = ? AND purpose = ?';

    public function __construct(private Sql $sql)
    {
    }

    public function put(OneTimeToken $token): void
    {
        // One statement, on the unique key (user_id, purpose): the user's earlier token of this
        // purpose becomes this one. No DELETE first (see the migration for why), and the row keeps
        // its id: a new primary key would move the row in every index, and the check that the
        // unique key is still unique then waits for the row of the next user if that is being
        // written at the same moment (LockingTest).
        $this->sql->execute(
            'INSERT INTO one_time_tokens (id, user_id, purpose, token_hash, expires_at, used_at, failed_attempts) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?) '
            // "Duplicate" means any unique key, also the id or the hash. Should one of those ever
            // collide with a row of another user or purpose, that row must not be overwritten: the
            // first assignment then tries NULL for a NOT NULL column, and the statement fails.
            . 'ON DUPLICATE KEY UPDATE user_id = IF(user_id = VALUE(user_id) AND purpose = VALUE(purpose), user_id, NULL), '
            . 'token_hash = VALUE(token_hash), expires_at = VALUE(expires_at), used_at = VALUE(used_at), '
            . 'failed_attempts = VALUE(failed_attempts)',
            [
                $token->id,
                $token->userId->value,
                $token->purpose->value,
                $token->tokenHash,
                Row::sqlTime($token->expiresAt),
                Row::nullableSqlTime($token->usedAt),
                $token->failedAttempts,
            ],
        );
    }

    public function findByHash(TokenPurpose $purpose, #[SensitiveParameter] string $tokenHash): ?OneTimeToken
    {
        return self::token($this->sql->row(self::SELECT, [$tokenHash, $purpose->value]));
    }

    public function lockByHash(TokenPurpose $purpose, #[SensitiveParameter] string $tokenHash): ?OneTimeToken
    {
        return self::token($this->sql->row(self::SELECT . ' FOR UPDATE', [$tokenHash, $purpose->value]));
    }

    public function markUsed(string $id, DateTimeImmutable $at): void
    {
        $this->sql->execute('UPDATE one_time_tokens SET used_at = ? WHERE id = ?', [Row::sqlTime($at), $id]);
    }

    public function addFailedAttempt(string $id): void
    {
        $this->sql->execute('UPDATE one_time_tokens SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
    }

    public function delete(string $id): void
    {
        // By primary key: a row that exists is locked alone, nothing around it.
        $this->sql->execute('DELETE FROM one_time_tokens WHERE id = ?', [$id]);
    }

    public function deleteExpiredAndUsed(DateTimeImmutable $now): int
    {
        return $this->sql->execute(
            'DELETE FROM one_time_tokens WHERE expires_at <= ? OR used_at IS NOT NULL',
            [Row::sqlTime($now)],
        );
    }

    /**
     * @param array<string, mixed>|null $columns
     */
    private static function token(?array $columns): ?OneTimeToken
    {
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return new OneTimeToken(
            $row->string('id'),
            UserId::fromString($row->string('user_id')),
            TokenPurpose::from($row->string('purpose')),
            $row->string('token_hash'),
            $row->time('expires_at'),
            $row->nullableTime('used_at'),
            $row->int('failed_attempts'),
        );
    }
}
