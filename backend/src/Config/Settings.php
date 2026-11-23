<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;

/**
 * Everything that differs between installations, as one typed, immutable object (HLD §4.5 "Config").
 *
 * Development and tests build it from environment variables, production from a PHP file outside the
 * document root (see SettingsLoader and config/settings.example.php). Both ways go through the same
 * checks, every value is required and there are no defaults: an installation that forgot a value
 * must not start with a guessed one.
 */
final readonly class Settings
{
    /**
     * Every setting, by its path in the settings table, with the environment variable that carries it.
     *
     * @var array<string, string>
     */
    public const array ENVIRONMENT_VARIABLES = [
        'environment' => 'APP_ENV',
        'release' => 'APP_RELEASE',
        'appUrl' => 'APP_URL',
        'logFile' => 'LOG_FILE',
        'database.host' => 'DB_HOST',
        'database.port' => 'DB_PORT',
        'database.name' => 'DB_NAME',
        'database.user' => 'DB_USER',
        'database.password' => 'DB_PASSWORD',
        'jwt.secret' => 'JWT_SECRET',
        'mail.dsn' => 'MAIL_DSN',
        'mail.fromAddress' => 'MAIL_FROM_ADDRESS',
        'mail.fromName' => 'MAIL_FROM_NAME',
        'consents.age18' => 'CONSENT_VERSION_AGE18',
        'consents.art9' => 'CONSENT_VERSION_ART9',
        'consents.privacy' => 'CONSENT_VERSION_PRIVACY',
    ];

    /** HS256 signs with a 256-bit hash, so a shorter key is the weakest part of the token (HLD §4.5). */
    public const int MIN_JWT_SECRET_BYTES = 32;

    public function __construct(
        public Environment $environment,
        /** The deployed release (git SHA). Reported by /health. */
        public string $release,
        /** Base URL of the web app, without a trailing slash. Links in mails start with it. */
        public string $appUrl,
        /** Where the application log is appended: an absolute path outside the document root, or a php:// stream. */
        public string $logFile,
        public DatabaseSettings $database,
        /** Signs the access tokens. The refresh token secrets are made with a key derived from it (HmacRefreshSecrets). */
        public Secret $jwtSecret,
        public MailSettings $mail,
        /** The versions of the consent texts in force. Stored with every consent (FR-ACC-02). */
        public ConsentVersions $consents,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $release) !== 1) {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('release') . ' must be 1 to 64 characters of A-Z, a-z, 0-9, ".", '
                . '"_" or "-" and start with a letter or a digit.',
            );
        }

        // No user name, query or fragment, and no trailing slash: links are built as appUrl + "/path?token=…".
        $url = '~\A(https?)://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?'
            . '(?:/[A-Za-z0-9._\~-]+)*\z~';
        if (preg_match($url, $appUrl, $match) !== 1) {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('appUrl') . ' must be an http(s) URL without a trailing slash, '
                . 'query or fragment.',
            );
        }
        if ($environment === Environment::Production && $match[1] !== 'https') {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('appUrl') . ' must use https in production.',
            );
        }

        // Absolute: cron starts in another directory than the web server, so a relative path would
        // be two files, and for the web one of them would lie inside the document root.
        if (
            preg_match('/\A[^\p{C}]{1,1024}\z/u', $logFile) !== 1
            || (!str_starts_with($logFile, '/') && !str_starts_with($logFile, 'php://'))
        ) {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('logFile') . ' must be an absolute file path or a php:// stream.',
            );
        }

        // The null transport takes every mail and sends nothing. The sender would then delete every
        // confirmation and reset mail as sent. The message names the setting, never the DSN: it
        // holds the password of the mailbox.
        if ($environment === Environment::Production && self::isNullTransport($mail->dsn)) {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('mail.dsn') . ' must name a real mail server in production.',
            );
        }

        // Counted in bytes, not characters: the key material is what matters.
        if (strlen($jwtSecret->reveal()) < self::MIN_JWT_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'Settings: ' . self::describe('jwt.secret') . ' must be at least '
                . self::MIN_JWT_SECRET_BYTES . ' bytes long.',
            );
        }
    }

    /**
     * Builds the settings from a table in the layout of config/settings.example.php.
     *
     * @param array<array-key, mixed> $table
     */
    public static function fromArray(array $table): self
    {
        $root = SettingsTable::root(
            $table,
            ['environment', 'release', 'appUrl', 'logFile', 'database', 'jwt', 'mail', 'consents'],
        );

        $environment = Environment::tryFrom($root->string('environment'))
            ?? throw $root->invalid('environment', 'must be "production", "development" or "testing".');
        $release = $root->string('release');
        $appUrl = $root->string('appUrl');
        $logFile = $root->string('logFile');

        $database = $root->section('database', ['host', 'port', 'name', 'user', 'password']);
        $jwt = $root->section('jwt', ['secret']);
        $mail = $root->section('mail', ['dsn', 'fromAddress', 'fromName']);
        $consents = $root->section('consents', ['age18', 'art9', 'privacy']);

        // Named arguments, so a value can never slip into a neighbouring parameter of the same type.
        return new self(
            environment: $environment,
            release: $release,
            appUrl: $appUrl,
            logFile: $logFile,
            database: new DatabaseSettings(
                host: $database->string('host'),
                port: $database->int('port'),
                name: $database->string('name'),
                user: $database->string('user'),
                password: $database->secret('password'),
            ),
            jwtSecret: $jwt->secret('secret'),
            mail: new MailSettings(
                dsn: $mail->secret('dsn'),
                fromAddress: $mail->string('fromAddress'),
                fromName: $mail->string('fromName'),
            ),
            consents: new ConsentVersions(
                age18: $consents->string('age18'),
                art9: $consents->string('art9'),
                privacy: $consents->string('privacy'),
            ),
        );
    }

    /**
     * Builds the settings from environment variables (development and tests).
     *
     * @param array<array-key, mixed> $environment Name => value, as returned by getenv(). Other variables are ignored.
     */
    public static function fromEnvironment(array $environment): self
    {
        $table = [];
        foreach (self::ENVIRONMENT_VARIABLES as $path => $variable) {
            $value = $environment[$variable] ?? null;
            // An unset variable and an empty one are the same thing to whoever wrote the environment.
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Settings: ' . self::describe($path) . ' is missing.');
            }

            if ($path === 'database.port') {
                // The environment only has text. Up to five plain digits always fit into an integer.
                if (preg_match('/\A[0-9]{1,5}\z/', $value) !== 1) {
                    throw new InvalidArgumentException(
                        'Settings: ' . self::describe($path) . ' must be a port number from 1 to 65535.',
                    );
                }
                $value = (int) $value;
            }

            $keys = explode('.', $path);
            if (count($keys) === 1) {
                $table[$keys[0]] = $value;
            } else {
                $table[$keys[0]][$keys[1]] = $value;
            }
        }

        return self::fromArray($table);
    }

    /**
     * Whether the mail DSN names symfony/mailer's null transport ("null://…", in any case).
     */
    private static function isNullTransport(Secret $dsn): bool
    {
        $scheme = strstr($dsn->reveal(), '://', true);

        return $scheme !== false && strtolower($scheme) === 'null';
    }

    /**
     * How a message names a setting: by its key in the settings file and by its environment variable,
     * so the message is clear whichever of the two the reader is editing.
     *
     * @internal
     */
    public static function describe(string $path): string
    {
        $variable = self::ENVIRONMENT_VARIABLES[$path] ?? null;

        return $variable === null ? "\"{$path}\"" : "\"{$path}\" (environment variable {$variable})";
    }
}
