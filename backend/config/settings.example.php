<?php

declare(strict_types=1);

/**
 * Template for the production settings file (HLD §4.5 "Config", §8).
 *
 * Copy it to a place OUTSIDE the document root (on IONOS: smoke-detector/shared/config/local.php)
 * and fill in every value. A release finds it there by itself (SmokeDetector\Config\ReleaseLayout); the
 * environment variable SETTINGS_FILE, when set, names another file instead. Never commit the copy.
 *
 * Every key is required and no other key is accepted. There are no defaults: the application refuses
 * to start with a clear message when a value is missing or invalid (SmokeDetector\Config\Settings).
 *
 * Development and tests do not use a file. They set the environment variable named in brackets
 * behind each key instead (docker-compose.yml, phpunit.xml, .github/workflows/ci.yml).
 */
return [
    // [APP_ENV] "production", "development" or "testing". Only production hides the details of an
    // unexpected error from the HTTP response.
    'environment' => 'production',

    // [APP_RELEASE] The deployed release, normally the git SHA. Reported by GET /api/v1/health.
    // 1 to 64 characters of A-Z, a-z, 0-9, ".", "_" or "-".
    'release' => 'unreleased',

    // [APP_URL] Base URL of the web app, without a trailing slash. Links in mails start with it
    // (…/verify-email?token=…). Must be https in production.
    'appUrl' => 'https://app.example.org',

    // [LOG_FILE] The application log, one JSON object per line. An absolute path (starting with "/")
    // outside the document root that PHP may append to, or a stream such as php://stderr. The cron job writes one line per run to
    // it, and keeps the time of its last successful run in the file "cron-heartbeat" in the same
    // directory (reported by GET /api/v1/health). With a stream there is no heartbeat.
    'logFile' => '/path/outside/the/document/root/logs/app.log',

    'database' => [
        // [DB_HOST] Host name or IP address of the MariaDB server.
        'host' => 'db.example.org',
        // [DB_PORT] An integer, not a string.
        'port' => 3306,
        // [DB_NAME] The schema. A-Z, a-z, 0-9 and "_" only.
        'name' => 'database_name',
        // [DB_USER]
        'user' => 'database_user',
        // [DB_PASSWORD] Secret.
        'password' => '',
    ],

    'jwt' => [
        // [JWT_SECRET] Secret. Signs the access tokens (HS256). The secrets of the refresh tokens
        // are made with a key derived from it, and the links of mails that wait to be sent are
        // encrypted with another key derived from it. At least 32 bytes of random data, for
        // example the output of: openssl rand -base64 48
        // Changing it ends every session: all users have to log in again. Mails that wait to be
        // sent at that moment are dropped (their links can be asked for again).
        'secret' => '',
    ],

    'mail' => [
        // [MAIL_DSN] Secret. A symfony/mailer transport, e.g. smtp://USER:PASSWORD@smtp.example.org:587
        // (URL-encode special characters in user and password). "null://null" sends nothing and is
        // for development only: production refuses it, since every mail would count as sent.
        // Only the cron job uses it (bin/cron, `mail:send`): no web request talks to the mail server.
        'dsn' => '',
        // [MAIL_FROM_ADDRESS] Sender address of every mail.
        'fromAddress' => 'no-reply@example.org',
        // [MAIL_FROM_NAME] Sender name of every mail.
        'fromName' => 'Smoke Detector',
    ],

    // The version of each consent text that is in force. The apps send the version of the text they
    // showed with every registration, and only the version named here is accepted and stored with
    // the consent (SRS FR-ACC-02, LEG-03). GET /api/v1/legal/consents answers these values. So a
    // version must change whenever its text changes, in the same deploy, and from then on an app
    // that still shows the old text cannot register anybody until it is updated.
    // 1 to 32 characters of A-Z, a-z, 0-9, ".", "_" or "-", e.g. the date.
    'consents' => [
        // [CONSENT_VERSION_AGE18] The wording of "I am 18 or older".
        'age18' => '2026-11-17',
        // [CONSENT_VERSION_ART9] The wording of the consent to processing smoking data (GDPR Art. 9).
        'art9' => '2026-11-17',
        // [CONSENT_VERSION_PRIVACY] The privacy policy.
        'privacy' => '2026-11-17',
    ],
];
