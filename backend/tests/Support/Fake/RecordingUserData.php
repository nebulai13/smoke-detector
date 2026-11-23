<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use ArrayObject;
use Closure;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Privacy\UserDataEraser;
use SmokeDetector\Application\Privacy\UserDataExporter;

/**
 * A table's exporter and eraser for unit tests: answers with what the test gives it and notes in a
 * shared log what it was asked to do, as "export <name> <user id>" and "erase <name> <user id>".
 */
final class RecordingUserData implements UserDataExporter, UserDataEraser
{
    /**
     * @param ArrayObject<int, string>          $log     Shared by all tables of a test, so the order shows.
     * @param array<array-key, mixed>|null      $export  What export() returns.
     * @param (Closure(UserId): void)|null      $onErase Runs when erase() is called, e.g. to fail.
     */
    public function __construct(
        private readonly string $name,
        private readonly ArrayObject $log,
        private readonly ?array $export = [],
        private readonly ?Closure $onErase = null,
    ) {
    }

    public function export(UserId $userId): ?array
    {
        $this->log->append("export {$this->name} {$userId->value}");

        return $this->export;
    }

    public function erase(UserId $userId): void
    {
        $this->log->append("erase {$this->name} {$userId->value}");
        if ($this->onErase !== null) {
            ($this->onErase)($userId);
        }
    }
}
