<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;

/**
 * How to reach the MariaDB schema ("database" section of the settings).
 */
final readonly class DatabaseSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public Secret $password,
    ) {
        // Host and schema name become part of the PDO DSN, where ";" starts the next option. The
        // narrow character sets make sure a value can never add or override a DSN option.
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9])?\z/', $host) !== 1) {
            throw new InvalidArgumentException(
                'Settings: "database.host" (environment variable DB_HOST) must be a host name or an IP address.',
            );
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                'Settings: "database.port" (environment variable DB_PORT) must be a port number from 1 to 65535.',
            );
        }
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                'Settings: "database.name" (environment variable DB_NAME) must be 1 to 64 characters of '
                . 'A-Z, a-z, 0-9 or "_".',
            );
        }
        if (preg_match('/\A[\x21-\x7E]{1,80}\z/', $user) !== 1) {
            throw new InvalidArgumentException(
                'Settings: "database.user" (environment variable DB_USER) must be 1 to 80 printable characters.',
            );
        }
    }
}
