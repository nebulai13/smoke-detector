<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use PDOException;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\EmailAlreadyRegistered;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The accounts in the `users` table.
 */
final readonly class PdoUserRepository implements UserRepository
{
    private const string SELECT = 'SELECT id, email, password_hash, display_name, avatar_key, locale, timezone, '
        . 'email_verified_at, created_at, token_version FROM users';

    /** MariaDB's error number for a value that is already in a unique index. */
    private const int DUPLICATE_ENTRY = 1062;

    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $id): ?User
    {
        return self::user($this->sql->row(self::SELECT . ' WHERE id = ?', [$id->value]));
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return self::user($this->sql->row(self::SELECT . ' WHERE email = ?', [$email->value]));
    }

    public function lock(UserId $id): ?User
    {
        return self::user($this->sql->row(self::SELECT . ' WHERE id = ? FOR UPDATE', [$id->value]));
    }

    public function add(User $user): void
    {
        $this->write(
            'INSERT INTO users (email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, '
            . 'created_at, token_version, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $user,
        );
    }

    public function update(User $user): void
    {
        $this->write(
            'UPDATE users SET email = ?, password_hash = ?, display_name = ?, avatar_key = ?, locale = ?, timezone = ?, '
            . 'email_verified_at = ?, created_at = ?, token_version = ? WHERE id = ?',
            $user,
        );
    }

    public function delete(UserId $id): void
    {
        $this->sql->execute('DELETE FROM users WHERE id = ?', [$id->value]);
    }

    public function deleteUnverifiedCreatedBefore(DateTimeImmutable $before): int
    {
        // Consents and tokens go with the account: their foreign keys are ON DELETE CASCADE.
        return $this->sql->execute(
            'DELETE FROM users WHERE email_verified_at IS NULL AND created_at < ?',
            [Row::sqlTime($before)],
        );
    }

    /**
     * @param string $statement With the columns in the order of the values below, the id last.
     */
    private function write(string $statement, User $user): void
    {
        try {
            $this->sql->execute($statement, [
                $user->email->value,
                $user->passwordHash,
                $user->displayName->value,
                $user->avatar->key,
                $user->locale->value,
                $user->timeZone->name,
                Row::nullableSqlTime($user->emailVerifiedAt),
                Row::sqlTime($user->createdAt),
                $user->tokenVersion,
                $user->id->value,
            ]);
        } catch (PDOException $failure) {
            // Only the email index: a duplicate id is a bug and must stay an error.
            if (
                ($failure->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
                && str_contains($failure->getMessage(), "'users_email'")
            ) {
                throw new EmailAlreadyRegistered();
            }

            throw $failure;
        }
    }

    /**
     * @param array<string, mixed>|null $columns
     */
    private static function user(?array $columns): ?User
    {
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return new User(
            UserId::fromString($row->string('id')),
            EmailAddress::fromInput($row->string('email')),
            $row->string('password_hash'),
            DisplayName::fromInput($row->string('display_name')),
            Avatar::fromInput($row->string('avatar_key')),
            Locale::fromInput($row->string('locale')),
            TimeZone::fromInput($row->string('timezone')),
            $row->nullableTime('email_verified_at'),
            $row->time('created_at'),
            $row->int('token_version'),
        );
    }
}
