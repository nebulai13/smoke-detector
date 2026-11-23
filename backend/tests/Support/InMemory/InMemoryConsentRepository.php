<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use LogicException;
use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\UserId;

/**
 * The consent records, in memory.
 */
final class InMemoryConsentRepository implements ConsentRepository, InMemoryState
{
    /** @var list<string> The methods that stored consents, in order: "add" or "replaceAll". */
    public array $calls = [];

    /** @var array<string, list<Consent>> By user id. */
    private array $consents = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(UserId $userId, array $consents): void
    {
        $this->calls[] = 'add';
        if (($this->consents[$userId->value] ?? []) !== []) {
            // What the primary key of the table says to a second row of the same consent.
            throw new LogicException('The user has consents already.');
        }
        $this->consents[$userId->value] = $consents;
    }

    public function replaceAll(UserId $userId, array $consents): void
    {
        $this->calls[] = 'replaceAll';
        $this->consents[$userId->value] = $consents;
    }

    public function ofUser(UserId $userId): array
    {
        $consents = $this->consents[$userId->value] ?? [];
        usort($consents, static fn (Consent $a, Consent $b): int => $a->type->value <=> $b->type->value);

        return $consents;
    }

    public function snapshot(): array
    {
        return $this->consents;
    }

    public function restore(array $snapshot): void
    {
        $consents = [];
        foreach ($snapshot as $userId => $list) {
            \assert(is_array($list));
            $consents[(string) $userId] = array_values(array_filter(
                $list,
                static fn (mixed $consent): bool => $consent instanceof Consent,
            ));
        }
        $this->consents = $consents;
    }
}
