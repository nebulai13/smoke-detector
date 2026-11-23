<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use Closure;
use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\EmailAlreadyRegistered;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;

/**
 * The accounts, in memory. Behaves like PdoUserRepository (both pass UserRepositoryContract),
 * except that deleting an account cannot take the rows of other repositories with it.
 */
final class InMemoryUserRepository implements UserRepository, InMemoryState
{
    /** @var array<string, User> By id. */
    private array $users = [];

    /** How many times add() fails with EmailAlreadyRegistered before it works: a lost race. */
    public int $failNextAdds = 0;

    /** Runs once, the next time an account is locked, before it is read. */
    public ?Closure $beforeLock = null;

    public function __construct(private readonly ?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $id): ?User
    {
        return $this->users[$id->value] ?? null;
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function lock(UserId $id): ?User
    {
        $this->transactions?->lock('users', 'UserRepository::lock()');
        // Stands for what another request does while this one waits for the lock.
        $beforeLock = $this->beforeLock;
        $this->beforeLock = null;
        if ($beforeLock !== null) {
            $beforeLock();
        }

        return $this->find($id);
    }

    public function add(User $user): void
    {
        if ($this->failNextAdds > 0) {
            $this->failNextAdds--;

            throw new EmailAlreadyRegistered();
        }
        if ($this->findByEmail($user->email) !== null) {
            throw new EmailAlreadyRegistered();
        }
        if (isset($this->users[$user->id->value])) {
            throw new LogicException('Duplicate user id.');
        }

        $this->users[$user->id->value] = $user;
    }

    public function update(User $user): void
    {
        $other = $this->findByEmail($user->email);
        if ($other !== null && !$other->id->equals($user->id)) {
            throw new EmailAlreadyRegistered();
        }
        if (isset($this->users[$user->id->value])) {
            $this->users[$user->id->value] = $user;
        }
    }

    public function deleteUnverifiedCreatedBefore(DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach ($this->users as $id => $user) {
            if (!$user->isVerified() && $user->createdAt < $before) {
                unset($this->users[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function delete(UserId $id): void
    {
        unset($this->users[$id->value]);
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return array_values($this->users);
    }

    public function snapshot(): array
    {
        return $this->users;
    }

    public function restore(array $snapshot): void
    {
        $users = [];
        foreach ($snapshot as $id => $user) {
            \assert($user instanceof User);
            $users[(string) $id] = $user;
        }
        $this->users = $users;
    }
}
