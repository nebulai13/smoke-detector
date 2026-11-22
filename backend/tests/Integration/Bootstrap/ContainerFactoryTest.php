<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Bootstrap;

use Psr\Clock\ClockInterface;
use ReflectionClass;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\AccessTokenIssuer;
use SmokeDetector\Application\Auth\Authenticate;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\RuleSets;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Infrastructure\Repository\PdoBaselineHistory;
use SmokeDetector\Infrastructure\Repository\PdoPlanRepository;
use SmokeDetector\Infrastructure\Rules\FileRuleSets;
use SmokeDetector\Application\Auth\AccessTokenVerifier;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\PasswordHasher;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Auth\TokenGenerator;
use SmokeDetector\Application\Health\CheckHealth;
use SmokeDetector\Application\Health\CronHeartbeat;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\MailTemplates;
use SmokeDetector\Application\Mail\MailValuesCipher;
use SmokeDetector\Application\Mail\SendQueuedMails;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Config\Settings;
use SmokeDetector\Console\Console;
use SmokeDetector\Http\Action\HealthAction;
use SmokeDetector\Infrastructure\Auth\Argon2idPasswordHasher;
use SmokeDetector\Infrastructure\Auth\JwtAccessTokens;
use SmokeDetector\Infrastructure\Auth\RandomTokenGenerator;
use SmokeDetector\Infrastructure\Auth\UuidV7Generator;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Clock\SystemClock;
use SmokeDetector\Infrastructure\Database\ConnectionFactory;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\PdoTransactionRunner;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Log\StreamLogger;
use SmokeDetector\Infrastructure\Health\FileCronHeartbeat;
use SmokeDetector\Infrastructure\Mail\FileMailTemplates;
use SmokeDetector\Infrastructure\Mail\InMemoryMailer;
use SmokeDetector\Infrastructure\Mail\LazyMailer;
use SmokeDetector\Infrastructure\Mail\SodiumMailValuesCipher;
use SmokeDetector\Infrastructure\Mail\SymfonyMailer;
use SmokeDetector\Infrastructure\Migration\Migrator;
use SmokeDetector\Infrastructure\Repository\PdoConsentRepository;
use SmokeDetector\Infrastructure\Repository\PdoMailOutbox;
use SmokeDetector\Infrastructure\Repository\PdoOneTimeTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoRateLimitCounters;
use SmokeDetector\Infrastructure\Repository\PdoRefreshTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\CountingConnectionFactory;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The one place where interfaces are tied to implementations (the composition root).
 */
final class ContainerFactoryTest extends IntegrationTestCase
{
    public function test_the_settings_are_available_as_they_were_given(): void
    {
        $settings = $this->settings();

        self::assertSame($settings, (new ContainerFactory())->create($settings)->get(Settings::class));
    }

    public function test_production_services_are_wired_to_their_interfaces(): void
    {
        $container = (new ContainerFactory())->create($this->settings());

        self::assertInstanceOf(SystemClock::class, $container->get(ClockInterface::class));
        self::assertInstanceOf(StreamLogger::class, $container->get(LoggerInterface::class));
        self::assertInstanceOf(PdoTransactionRunner::class, $container->get(TransactionRunner::class));
        self::assertInstanceOf(CheckHealth::class, $container->get(CheckHealth::class));
        self::assertInstanceOf(HealthAction::class, $container->get(HealthAction::class));
        self::assertInstanceOf(Migrator::class, $container->get(Migrator::class));
    }

    public function test_NFR_05_the_auth_services_are_wired_to_their_production_implementations(): void
    {
        $settings = $this->settings();
        $container = (new ContainerFactory())->create($settings);

        // Argon2id, real randomness, and JWTs: no test double may end up in production by default.
        self::assertInstanceOf(Argon2idPasswordHasher::class, $container->get(PasswordHasher::class));
        self::assertInstanceOf(RandomTokenGenerator::class, $container->get(TokenGenerator::class));
        self::assertInstanceOf(UuidV7Generator::class, $container->get(IdGenerator::class));
        self::assertInstanceOf(JwtAccessTokens::class, $container->get(AccessTokenIssuer::class));
        self::assertSame($container->get(AccessTokenIssuer::class), $container->get(AccessTokenVerifier::class));
        self::assertInstanceOf(PdoUserRepository::class, $container->get(UserRepository::class));
        self::assertInstanceOf(PdoConsentRepository::class, $container->get(ConsentRepository::class));
        self::assertInstanceOf(PdoOneTimeTokenRepository::class, $container->get(OneTimeTokenRepository::class));
        self::assertInstanceOf(PdoRefreshTokenRepository::class, $container->get(RefreshTokenRepository::class));
        self::assertInstanceOf(PdoRateLimitCounters::class, $container->get(RateLimitCounters::class));
        self::assertInstanceOf(FileMailTemplates::class, $container->get(MailTemplates::class));
        self::assertSame($settings->consents, $container->get(ConsentVersions::class));
    }

    public function test_the_plan_and_user_data_services_are_wired_to_their_production_implementations(): void
    {
        $container = (new ContainerFactory())->create($this->settings());

        self::assertInstanceOf(PdoPlanRepository::class, $container->get(PlanRepository::class));
        self::assertInstanceOf(PdoBaselineHistory::class, $container->get(BaselineHistory::class));
        self::assertInstanceOf(FileRuleSets::class, $container->get(RuleSets::class));
        $registry = $container->get(UserDataRegistry::class);
        self::assertInstanceOf(UserDataRegistry::class, $registry);
        self::assertNotSame([], $registry->tables());
        // Built once per request, like everything else.
        self::assertSame($registry, $container->get(UserDataRegistry::class));
    }

    public function test_the_rule_table_in_force_is_found_from_the_container_and_is_v1(): void
    {
        $rules = (new ContainerFactory())->create($this->settings())->get(RuleSets::class);
        self::assertInstanceOf(RuleSets::class, $rules);

        self::assertSame('v1', ContainerFactory::RULES_VERSION);
        self::assertSame('v1', $rules->current()->version);
        self::assertSame(80, $rules->current()->plan->normalLimitPercent);
    }

    public function test_FR_ACC_08_the_gate_of_the_protected_routes_looks_the_account_up(): void
    {
        $container = (new ContainerFactory())->create($this->settings(), [ClockInterface::class => FrozenClock::at('2026-11-17 12:00:00')]);
        $issuer = $container->get(AccessTokenIssuer::class);
        $authenticate = $container->get(Authenticate::class);
        self::assertInstanceOf(AccessTokenIssuer::class, $issuer);
        self::assertInstanceOf(Authenticate::class, $authenticate);

        // A perfectly signed token for an account that does not exist.
        $this->expectException(InvalidAccessToken::class);

        $authenticate->execute($issuer->issue(TestData::userId(1), 0)->token);
    }

    public function test_NFR_05_access_tokens_are_signed_with_the_secret_of_the_settings(): void
    {
        $container = (new ContainerFactory())->create($this->settings(), [ClockInterface::class => FrozenClock::at('2026-11-17 12:00:00')]);
        $issuer = $container->get(AccessTokenIssuer::class);
        self::assertInstanceOf(AccessTokenIssuer::class, $issuer);

        [$header, $payload, $signature] = explode('.', $issuer->issue(TestData::userId(1), 0)->token);

        self::assertSame(
            rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $this->settings()->jwtSecret->reveal(), true)), '+/', '-_'), '='),
            $signature,
        );
    }

    public function test_mail_is_queued_in_the_outbox_and_only_its_sender_reaches_the_transport_of_the_settings(): void
    {
        $container = (new ContainerFactory())->create($this->settings());

        self::assertInstanceOf(PdoMailOutbox::class, $container->get(MailOutbox::class));
        self::assertInstanceOf(SodiumMailValuesCipher::class, $container->get(MailValuesCipher::class));
        self::assertInstanceOf(SendQueuedMails::class, $container->get(SendQueuedMails::class));
        // The mailer is built when the first mail is sent, not before (see LazyMailer).
        self::assertInstanceOf(LazyMailer::class, $container->get(Mailer::class));
        // MAIL_DSN of the tests is "null://null".
        self::assertInstanceOf(NullTransport::class, $container->get(TransportInterface::class));
        self::assertInstanceOf(SymfonyMailer::class, $container->get(SymfonyMailer::class));
    }

    public function test_NFR_05_the_values_of_a_queued_mail_are_encrypted_with_a_key_from_the_jwt_secret(): void
    {
        $cipher = (new ContainerFactory())->create($this->settings())->get(MailValuesCipher::class);
        self::assertInstanceOf(MailValuesCipher::class, $cipher);

        $sealed = (string) base64_decode($cipher->seal(['url' => 'https://app.example.test/x']), true);

        self::assertSame(
            '{"url":"https://app.example.test/x"}',
            sodium_crypto_secretbox_open(
                substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                hash_hkdf('sha256', $this->settings()->jwtSecret->reveal(), 32, 'smoke-detector mail outbox values v1'),
            ),
        );
    }

    public function test_FR_I18N_01_every_mail_of_the_application_is_made_from_the_real_templates_by_the_sender_of_the_outbox(): void
    {
        // A template that does not fit the values the application queues shows only when the mail
        // is made, which is no longer in the request. So it is shown here, for every mail and language.
        $inbox = new InMemoryMailer();
        $clock = FrozenClock::at('2026-11-17 12:00:00');
        $container = (new ContainerFactory())->create($this->settings(), [ClockInterface::class => $clock, Mailer::class => $inbox]);
        $mails = $container->get(AccountMails::class);
        $users = $container->get(UserRepository::class);
        $transactions = $container->get(TransactionRunner::class);
        $sender = $container->get(SendQueuedMails::class);
        self::assertInstanceOf(AccountMails::class, $mails);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(TransactionRunner::class, $transactions);
        self::assertInstanceOf(SendQueuedMails::class, $sender);
        $german = TestAccounts::user(TestData::uuid(1), 'anna@example.org', $clock->now(), $clock->now(), Locale::De);
        $english = TestAccounts::user(TestData::uuid(2), 'bob@example.org', $clock->now(), $clock->now(), Locale::En);
        $users->add($german);
        $users->add($english);

        $transactions->run(static function () use ($mails, $german, $english, $clock): void {
            foreach ([$german, $english] as $user) {
                $mails->confirmEmail($user, 'abc', $clock->now());
                $mails->alreadyRegistered($user, $clock->now());
                $mails->resetPassword($user, 'def', $clock->now());
            }
        });
        $report = $sender->execute();

        self::assertSame([6, 0, 0], [$report->sent, $report->retried, $report->givenUp]);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM mail_outbox'));
        $sent = [];
        foreach ($inbox->sent() as $mail) {
            $sent[$mail->to->value][$mail->template] = $mail;
        }
        self::assertSame(['anna@example.org', 'bob@example.org'], array_keys($sent));
        foreach ($sent as $ofOneUser) {
            self::assertSame(['confirm_email', 'already_registered', 'reset_password'], array_keys($ofOneUser));
            self::assertStringContainsString($this->settings()->appUrl . '/verify-email?token=abc', $ofOneUser['confirm_email']->text);
            self::assertStringContainsString($this->settings()->appUrl . '/login', $ofOneUser['already_registered']->text);
            self::assertStringContainsString($this->settings()->appUrl . '/reset-password?token=def', $ofOneUser['reset_password']->text);
        }
        self::assertSame('Fast geschafft: Bestätige deine E-Mail-Adresse', $sent['anna@example.org']['confirm_email']->subject);
        self::assertSame('Almost there: confirm your email address', $sent['bob@example.org']['confirm_email']->subject);
    }

    public function test_the_cron_heartbeat_is_a_file_next_to_the_log_and_health_reads_it(): void
    {
        $container = (new ContainerFactory())->create($this->settings());

        self::assertInstanceOf(FileCronHeartbeat::class, $container->get(CronHeartbeat::class));
        // The tests log to php://memory: there is no place for a heartbeat, so none is known.
        $health = $container->get(CheckHealth::class);
        self::assertInstanceOf(CheckHealth::class, $health);
        self::assertNull($health->execute()->cronLastRunAt);
    }

    public function test_the_cron_job_sends_the_mail_first_and_then_cleans_up(): void
    {
        // No web request sends mail, so a sender that no cron step runs would mean that nobody
        // ever gets a link. And the cleanup must be a step too (FR-ACC-03).
        $console = (new ContainerFactory())->create($this->settings())->get(Console::class);
        self::assertInstanceOf(Console::class, $console);

        $commands = [];
        foreach ($console->commands() as $command) {
            $commands[$command->name()] = $command->description();
        }

        self::assertSame(['cron:run', 'housekeeping:purge', 'mail:send', 'migrate'], array_keys($commands));
        self::assertSame('Runs what the cron job runs every minute: mail:send, housekeeping:purge.', $commands['cron:run']);
    }

    public function test_mail_settings_that_cannot_work_do_not_keep_the_other_commands_from_running(): void
    {
        $settings = SettingsFixture::modified($this->settings(), mailDsn: 'carrier-pigeon://user:secret@example.test');
        $console = (new ContainerFactory())->create($settings)->get(Console::class);
        self::assertInstanceOf(Console::class, $console);
        $output = new BufferedOutput();

        self::assertSame(0, $console->run(['housekeeping:purge'], $output));
        self::assertSame([], $output->errors);
    }

    public function test_NFR_05_rate_limit_keys_are_hashed_with_a_key_from_the_jwt_secret(): void
    {
        $settings = $this->settings();

        $hasher = (new ContainerFactory())->create($settings)->get(\SmokeDetector\Application\RateLimit\RateLimitKeyHasher::class);

        self::assertInstanceOf(\SmokeDetector\Infrastructure\RateLimit\HmacRateLimitKeyHasher::class, $hasher);
        self::assertSame(
            bin2hex(hash_hmac('sha256', 'anna@example.org', hash_hkdf('sha256', $settings->jwtSecret->reveal(), 32, 'smoke-detector rate limit keys v1'), true)),
            bin2hex($hasher->hash('anna@example.org')),
        );
    }

    public function test_the_mail_server_gets_ten_seconds_for_each_answer_and_not_phps_sixty(): void
    {
        // The sender checks its 40 seconds between mails. A mail server that stops answering must
        // not hold one mail for a minute per read (PHP's default_socket_timeout).
        $settings = SettingsFixture::modified($this->settings(), mailDsn: 'smtp://mailbox:its-password@smtp.example.test:587');

        $transport = (new ContainerFactory())->create($settings)->get(\Symfony\Component\Mailer\Transport\TransportInterface::class);

        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\Smtp\SmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream::class, $stream);
        self::assertSame(10.0, $stream->getTimeout());
    }

    public function test_a_mail_transport_without_a_connection_is_left_as_it_is(): void
    {
        $settings = SettingsFixture::modified($this->settings(), mailDsn: 'null://null');

        $transport = (new ContainerFactory())->create($settings)->get(\Symfony\Component\Mailer\Transport\TransportInterface::class);

        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\NullTransport::class, $transport);
    }

    public function test_everything_in_one_container_shares_one_database_connection(): void
    {
        // Otherwise a repository would write outside the transaction its use case opened.
        $connections = new CountingConnectionFactory($this->service(ConnectionFactory::class));
        $container = (new ContainerFactory())->create($this->settings(), [ConnectionFactory::class => $connections]);

        $database = $container->get(Database::class);
        $sql = $container->get(Sql::class);
        $transactions = $container->get(TransactionRunner::class);
        self::assertInstanceOf(Database::class, $database);
        self::assertInstanceOf(Sql::class, $sql);
        self::assertInstanceOf(TransactionRunner::class, $transactions);

        self::assertSame($database, $container->get(Database::class));
        self::assertSame(0, $connections->connections, 'Building the services must not connect.');

        $inside = $transactions->run(static fn (): mixed => $sql->value('SELECT CONNECTION_ID()'));

        self::assertSame($inside, $sql->value('SELECT CONNECTION_ID()'));
        self::assertSame(1, $connections->connections);
    }

    public function test_an_entry_can_be_replaced_for_a_test(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');

        $container = (new ContainerFactory())->create($this->settings(), [ClockInterface::class => $clock]);

        self::assertSame($clock, $container->get(ClockInterface::class));
    }

    public function test_every_housekeeping_use_case_can_be_run_from_the_console(): void
    {
        // A cleanup that exists as a class and is called by nothing deletes nothing. Whatever lives
        // in Application/Housekeeping must be what some console command runs, so that the cron job
        // has something to call (FR-ACC-03: unconfirmed registrations are deleted after 24 hours).
        $console = (new ContainerFactory())->create($this->settings())->get(Console::class);
        self::assertInstanceOf(Console::class, $console);
        $runByACommand = [];
        foreach ($console->commands() as $command) {
            foreach ((new ReflectionClass($command))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $runByACommand[] = (string) $parameter->getType();
            }
        }

        $useCases = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Application/Housekeeping/*.php') ?: [] as $file) {
            $class = 'SmokeDetector\\Application\\Housekeeping\\' . basename($file, '.php');
            if (method_exists($class, 'execute')) {
                $useCases[] = $class;
            }
        }

        self::assertNotSame([], $useCases);
        self::assertSame([], array_values(array_diff($useCases, $runByACommand)), 'Housekeeping use cases that no console command runs.');
    }

    public function test_the_console_runs_the_cleanup_against_the_database(): void
    {
        $container = (new ContainerFactory())->create($this->settings(), [ClockInterface::class => FrozenClock::at('2026-11-17 12:00:00')]);
        $console = $container->get(Console::class);
        self::assertInstanceOf(Console::class, $console);
        $this->sql()->execute(
            'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)',
            [TestData::uuid(1), 'old@example.org', 'x', 'Old One', 'avatar-01', 'de', 'Europe/Berlin', '2026-11-16 11:59:59'],
        );
        $output = new BufferedOutput();

        self::assertSame(0, $console->run(['housekeeping:purge'], $output));

        self::assertSame(['Deleted 1 unconfirmed registration, 0 mail link tokens, 0 sessions, 0 rate limit counters and 0 queued mails.'], $output->lines);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));
    }

    public function test_the_console_knows_the_migrate_command(): void
    {
        $console = (new ContainerFactory())->create($this->settings())->get(Console::class);
        self::assertInstanceOf(Console::class, $console);
        $output = new BufferedOutput();

        self::assertSame(0, $console->run(['migrate'], $output));
        self::assertSame(['Nothing to migrate.'], $output->lines);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = (new ContainerFactory())->create($this->settings())->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
