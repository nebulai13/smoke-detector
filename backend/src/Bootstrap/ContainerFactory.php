<?php

declare(strict_types=1);

namespace SmokeDetector\Bootstrap;

use DI\ContainerBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\AccessTokenIssuer;
use SmokeDetector\Application\Auth\AccessTokenVerifier;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\PasswordHasher;
use SmokeDetector\Application\Auth\RefreshSecrets;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Auth\TokenGenerator;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\LeagueResults;
use SmokeDetector\Application\Day\XpLedger;
use SmokeDetector\Application\Friends\Blocks;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\InviteCodeGenerator;
use SmokeDetector\Application\Friends\InviteCodes;
use SmokeDetector\Application\Health\CheckHealth;
use SmokeDetector\Application\Health\CronHeartbeat;
use SmokeDetector\Application\Health\DatabaseProbe;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\MailTemplates;
use SmokeDetector\Application\Mail\MailValuesCipher;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\RuleSets;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Application\RateLimit\RateLimitKeyHasher;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Settings\UserSettings;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Config\MailSettings;
use SmokeDetector\Config\Settings;
use SmokeDetector\Console\Command;
use SmokeDetector\Console\Console;
use SmokeDetector\Console\CronRunCommand;
use SmokeDetector\Console\FinalizeCompetitionsCommand;
use SmokeDetector\Console\FinalizeDaysCommand;
use SmokeDetector\Console\MailSendCommand;
use SmokeDetector\Console\MigrateCommand;
use SmokeDetector\Console\PurgeCommand;
use SmokeDetector\Http\Friends\FriendsResponder;
use SmokeDetector\Infrastructure\Auth\Argon2idPasswordHasher;
use SmokeDetector\Infrastructure\Auth\HmacRefreshSecrets;
use SmokeDetector\Infrastructure\Auth\JwtAccessTokens;
use SmokeDetector\Infrastructure\Auth\RandomTokenGenerator;
use SmokeDetector\Infrastructure\Auth\UuidV7Generator;
use SmokeDetector\Infrastructure\Clock\SystemClock;
use SmokeDetector\Infrastructure\Database\ConnectionFactory;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\PdoDatabaseProbe;
use SmokeDetector\Infrastructure\Database\PdoTransactionRunner;
use SmokeDetector\Infrastructure\Friends\RandomInviteCodes;
use SmokeDetector\Infrastructure\Log\StreamLogger;
use SmokeDetector\Infrastructure\Health\FileCronHeartbeat;
use SmokeDetector\Infrastructure\Mail\FileMailTemplates;
use SmokeDetector\Infrastructure\Mail\LazyMailer;
use SmokeDetector\Infrastructure\Mail\SodiumMailValuesCipher;
use SmokeDetector\Infrastructure\Mail\SymfonyMailer;
use SmokeDetector\Infrastructure\Migration\Migrator;
use SmokeDetector\Infrastructure\Migration\PhinxMigrator;
use SmokeDetector\Infrastructure\Privacy\UserDataTables;
use SmokeDetector\Infrastructure\RateLimit\HmacRateLimitKeyHasher;
use SmokeDetector\Infrastructure\Repository\PdoBadgeRecords;
use SmokeDetector\Infrastructure\Repository\PdoBaselineHistory;
use SmokeDetector\Infrastructure\Repository\PdoBlocks;
use SmokeDetector\Infrastructure\Repository\PdoConsentRepository;
use SmokeDetector\Infrastructure\Repository\PdoDayRecords;
use SmokeDetector\Infrastructure\Repository\PdoDuels;
use SmokeDetector\Infrastructure\Repository\PdoLeagueResults;
use SmokeDetector\Infrastructure\Repository\PdoFriendLinks;
use SmokeDetector\Infrastructure\Repository\PdoInviteCodes;
use SmokeDetector\Infrastructure\Repository\PdoMailOutbox;
use SmokeDetector\Infrastructure\Repository\PdoMilestoneRecords;
use SmokeDetector\Infrastructure\Repository\PdoOneTimeTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoPlanHistory;
use SmokeDetector\Infrastructure\Repository\PdoPlanRepository;
use SmokeDetector\Infrastructure\Repository\PdoProgressRecords;
use SmokeDetector\Infrastructure\Repository\PdoRateLimitCounters;
use SmokeDetector\Infrastructure\Repository\PdoRefreshTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoSmokeLogs;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Infrastructure\Repository\PdoUserSettings;
use SmokeDetector\Infrastructure\Repository\PdoXpLedger;
use SmokeDetector\Infrastructure\Repository\PdoZoneHistory;
use SmokeDetector\Infrastructure\Rules\FileRuleSets;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * The composition root: the one place that decides which implementation stands behind an interface.
 *
 * Concrete classes (actions, use cases, Sql, …) need no entry: the container builds them from their
 * constructor types. An entry is needed for an interface, and for a class that takes a value from the
 * settings. One container lives for one request or one console command, and every entry is created
 * once in it, so all repositories share one database connection.
 */
final readonly class ContainerFactory
{
    /**
     * The rule table in force: backend/config/rules/<version>.php. New results are calculated with
     * it and name it. A released table is never edited: tuning means a new file and a new name here.
     */
    public const string RULES_VERSION = 'v1';

    /**
     * How long the mail server gets for each answer, instead of PHP's default_socket_timeout (60 s).
     * The sender starts no mail after its 40 seconds (SendQueuedMails); a mail server that stops
     * answering must not hold one mail for a minute per read.
     */
    public const float SMTP_TIMEOUT_SECONDS = 10.0;

    /**
     * @param array<string, mixed> $overrides Entries that replace the ones below. For tests: fakes for
     *                                        the mailer, the clock and so on.
     */
    public function create(Settings $settings, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        $builder->addDefinitions([
            Settings::class => $settings,

            // Time and log
            ClockInterface::class => autowire(SystemClock::class),
            LoggerInterface::class => autowire(StreamLogger::class)->constructorParameter('file', $settings->logFile),

            // HTTP messages
            Psr17Factory::class => autowire(),
            ResponseFactoryInterface::class => get(Psr17Factory::class),
            StreamFactoryInterface::class => get(Psr17Factory::class),

            // Database
            ConnectionFactory::class => autowire(PdoConnectionFactory::class)
                ->constructorParameter('settings', $settings->database),
            Database::class => autowire(LazyPdoDatabase::class),
            TransactionRunner::class => autowire(PdoTransactionRunner::class),
            DatabaseProbe::class => autowire(PdoDatabaseProbe::class),
            // The time of the last successful cron run, in a file next to the application log.
            CronHeartbeat::class => static fn (): CronHeartbeat => FileCronHeartbeat::besideLogFile($settings->logFile),
            // Use cases get the one value they need, never the settings with every secret in them.
            CheckHealth::class => autowire()->constructorParameter('release', $settings->release),
            AccountMails::class => autowire()->constructorParameter('appUrl', $settings->appUrl),
            Migrator::class => autowire(PhinxMigrator::class)
                ->constructorParameter('schema', $settings->database->name)
                // backend/migrations in the repository, app/migrations in a release.
                ->constructorParameter('migrationsPath', dirname(__DIR__, 2) . '/migrations'),

            // Accounts and sessions (HLD §4.5)
            UserRepository::class => autowire(PdoUserRepository::class),
            ConsentRepository::class => autowire(PdoConsentRepository::class),
            OneTimeTokenRepository::class => autowire(PdoOneTimeTokenRepository::class),
            RefreshTokenRepository::class => autowire(PdoRefreshTokenRepository::class),
            RateLimitCounters::class => autowire(PdoRateLimitCounters::class),
            // The keys of the counters are hashed with a key derived from jwt.secret for that
            // purpose alone (see HmacRateLimitKeyHasher).
            RateLimitKeyHasher::class => autowire(HmacRateLimitKeyHasher::class)->constructorParameter('secret', $settings->jwtSecret),
            PasswordHasher::class => autowire(Argon2idPasswordHasher::class),
            TokenGenerator::class => autowire(RandomTokenGenerator::class),
            IdGenerator::class => autowire(UuidV7Generator::class),
            JwtAccessTokens::class => autowire()->constructorParameter('secret', $settings->jwtSecret),
            // The secrets of the refresh tokens come from the same setting, through a key derived
            // for that purpose alone (see HmacRefreshSecrets).
            RefreshSecrets::class => autowire(HmacRefreshSecrets::class)->constructorParameter('secret', $settings->jwtSecret),
            AccessTokenIssuer::class => get(JwtAccessTokens::class),
            AccessTokenVerifier::class => get(JwtAccessTokens::class),
            ConsentVersions::class => $settings->consents,

            // Reduction plan (HLD §4.5 "Account")
            PlanRepository::class => autowire(PdoPlanRepository::class),
            BaselineHistory::class => autowire(PdoBaselineHistory::class),
            PlanHistory::class => autowire(PdoPlanHistory::class),
            RuleSets::class => autowire(FileRuleSets::class)
                // backend/config/rules in the repository, app/config/rules in a release.
                ->constructorParameter('directory', dirname(__DIR__, 2) . '/config/rules')
                ->constructorParameter('currentVersion', self::RULES_VERSION),

            // Logging, check-in and day finalization (HLD §4.6)
            ZoneHistory::class => autowire(PdoZoneHistory::class),
            SmokeLogs::class => autowire(PdoSmokeLogs::class),
            DayRecords::class => autowire(PdoDayRecords::class),
            ProgressRecords::class => autowire(PdoProgressRecords::class),
            XpLedger::class => autowire(PdoXpLedger::class),

            // Friends (HLD §4.7)
            InviteCodes::class => autowire(PdoInviteCodes::class),
            InviteCodeGenerator::class => autowire(RandomInviteCodes::class),
            FriendLinks::class => autowire(PdoFriendLinks::class),
            Blocks::class => autowire(PdoBlocks::class),
            // The invite link is the address of the web app with the code behind a "#".
            FriendsResponder::class => autowire()->constructorParameter('appUrl', $settings->appUrl),

            // Duels (HLD §4.8)
            Duels::class => autowire(PdoDuels::class),

            // Leagues (HLD §4.9)
            LeagueResults::class => autowire(PdoLeagueResults::class),

            // Badges (HLD §4.10)
            BadgeRecords::class => autowire(PdoBadgeRecords::class),

            // Health milestones for friends, and the settings (HLD §4.11)
            MilestoneRecords::class => autowire(PdoMilestoneRecords::class),
            UserSettings::class => autowire(PdoUserSettings::class),

            // Data export and account deletion: the tables with user data are listed in UserDataTables.
            UserDataRegistry::class => factory(static fn (UserDataTables $tables): UserDataRegistry => $tables->registry()),

            // Mail: a use case queues its mail in the outbox (AccountMails), inside its transaction.
            // No web request sends one. The cron job does, with `mail:send` (SendQueuedMails).
            MailOutbox::class => autowire(PdoMailOutbox::class),
            // The links of a queued mail are stored encrypted, with a key derived from jwt.secret
            // for that purpose alone (see SodiumMailValuesCipher).
            MailValuesCipher::class => autowire(SodiumMailValuesCipher::class)->constructorParameter('secret', $settings->jwtSecret),
            MailSettings::class => $settings->mail,
            MailTemplates::class => autowire(FileMailTemplates::class)
                // backend/templates/mail in the repository, app/templates/mail in a release.
                ->constructorParameter('directory', dirname(__DIR__, 2) . '/templates/mail'),
            // Building the transport does not connect. That happens when the first mail is sent.
            TransportInterface::class => static fn (): TransportInterface => self::transport($settings->mail),
            // Built when the first mail is sent: nothing but the sender of the outbox ever needs
            // the mail settings to be right (see LazyMailer).
            Mailer::class => static fn (ContainerInterface $container): Mailer => new LazyMailer(
                static function () use ($container): Mailer {
                    $mailer = $container->get(SymfonyMailer::class);
                    \assert($mailer instanceof Mailer);

                    return $mailer;
                },
            ),

            // Console: every command of bin/console is listed here.
            Console::class => static function (ContainerInterface $container): Console {
                $mailSend = self::command($container, MailSendCommand::class);
                $finalizeDays = self::command($container, FinalizeDaysCommand::class);
                $finalizeCompetitions = self::command($container, FinalizeCompetitionsCommand::class);
                $purge = self::command($container, PurgeCommand::class);

                return new Console(
                    self::command($container, MigrateCommand::class),
                    $mailSend,
                    $finalizeDays,
                    $finalizeCompetitions,
                    $purge,
                    // What the cron job runs every minute, in this order (HLD §5.2, §8).
                    new CronRunCommand(
                        self::service($container, CronHeartbeat::class),
                        self::service($container, ClockInterface::class),
                        $mailSend,
                        $finalizeDays,
                        $finalizeCompetitions,
                        $purge,
                    ),
                );
            },
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }

    /**
     * The mail transport of the settings. An SMTP connection gets SMTP_TIMEOUT_SECONDS for each
     * answer; a transport without a socket (null://, a local sendmail process) is left as it is.
     */
    private static function transport(MailSettings $mail): TransportInterface
    {
        $transport = Transport::fromDsn($mail->dsn->reveal());
        if ($transport instanceof SmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::SMTP_TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function command(ContainerInterface $container, string $class): Command
    {
        return self::service($container, $class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        \assert($service instanceof $class);

        return $service;
    }
}
