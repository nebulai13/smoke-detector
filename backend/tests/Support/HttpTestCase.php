<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Before;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\AccessTokenIssuer;
use SmokeDetector\Application\IdGenerator;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\MailSendReport;
use SmokeDetector\Application\Mail\SendQueuedMails;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Http\AppFactory;
use SmokeDetector\Http\Routes;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Mail\InMemoryMailer;
use SmokeDetector\Tests\Support\Fake\CountingMailOutbox;
use SmokeDetector\Tests\Support\Fake\CronOnlyMailer;
use SmokeDetector\Tests\Support\Fake\ForbiddenTransport;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\Http\Contract;
use Symfony\Component\Mailer\Transport\TransportInterface;

use function DI\decorate;

/**
 * Base class of every HTTP test. Requests go through the real Slim application in this process (no
 * web server), against the real test database, and EVERY request and response is checked against
 * the contract api/openapi.yaml. So every HTTP test is a contract test.
 *
 * Three ways to send a request, by what the contract says about it:
 *
 * - call():             the normal case. The request must be valid by the contract, and the response
 *                       must be one the contract documents for that operation (status, content type,
 *                       every field, no extra field).
 * - callInvalid():      for "the server rejects bad input" tests. The request must BREAK the contract
 *                       (otherwise the test is not testing what it thinks, or the contract is too
 *                       loose), and the response must still be documented for the operation.
 * - callUndocumented(): for paths and methods that are deliberately not in the contract (unknown
 *                       routes, test-only routes). An error response must be valid problem details.
 *
 * The application is built once per test, on the first request. Override settings(), services() and
 * routes() to change it, and use clock(), logs() and mails() to steer and to inspect it.
 *
 * Mail: no request sends mail. It queues the mail, and the cron job sends it (HLD §4.5 "Mail").
 * Every request is checked for that: one that hands a mail to the mailer or touches the SMTP
 * transport fails the test. In the place of the cron job, the harness runs the real sender of the
 * outbox right after each request that queued a mail, so mails() holds what the user finds in the
 * mailbox a minute later. holdMail() stops that, deliverMail() runs the sender by hand.
 *
 * Acting as a user: givenUser() stores an account, authorization($user) gives the headers of a
 * logged-in request, logIn() logs in over HTTP, confirmEmail() confirms a registration. Requests come from CLIENT_ADDRESS unless
 * fromClientAddress() says otherwise.
 */
abstract class HttpTestCase extends IntegrationTestCase
{
    /** @var App<ContainerInterface>|null */
    private ?App $app = null;

    private ?ContainerInterface $container = null;

    private FrozenClock $clock;

    private RecordingLogger $logger;

    private InMemoryMailer $mailer;

    private CronOnlyMailer $mailServer;

    private ForbiddenTransport $smtp;

    private ?CountingMailOutbox $outbox = null;

    private bool $cronRunsAfterEachRequest = true;

    private string $clientAddress = self::CLIENT_ADDRESS;

    /** The address requests come from unless a test says otherwise (a documentation address, RFC 5737). */
    protected const string CLIENT_ADDRESS = '203.0.113.10';

    #[Before]
    final protected function startWithAFreshApplication(): void
    {
        $this->app = null;
        $this->container = null;
        $this->clock = FrozenClock::at('2026-11-17 12:00:00');
        $this->logger = new RecordingLogger();
        $this->mailer = new InMemoryMailer();
        $this->mailServer = new CronOnlyMailer($this->mailer);
        $this->smtp = new ForbiddenTransport();
        $this->outbox = null;
        $this->cronRunsAfterEachRequest = true;
        $this->clientAddress = self::CLIENT_ADDRESS;
    }

    /**
     * The contract the requests and responses are checked against.
     */
    protected function contractFile(): string
    {
        return Paths::openApi();
    }

    /**
     * Container entries that replace the real ones in this test class, e.g. a fake mailer:
     * [Mailer::class => new InMemoryMailer()]. Use \DI\value() to register a closure as a value.
     *
     * @return array<string, mixed>
     */
    protected function services(): array
    {
        return [];
    }

    /**
     * Test-only routes, added to the real ones. Paths are relative to /api/v1. Use non-static closures.
     *
     * @param App<ContainerInterface> $app
     */
    protected function routes(App $app): void
    {
    }

    /**
     * The clock of the application under test. It stands at 2026-11-17 12:00:00 UTC until moved.
     */
    protected function clock(): FrozenClock
    {
        return $this->clock;
    }

    /**
     * What the application logged during this test.
     */
    protected function logs(): RecordingLogger
    {
        return $this->logger;
    }

    /**
     * The mails that have arrived: what the sender of the outbox handed to the mail server of the
     * tests. Nothing leaves the process. Unless holdMail() was called, the mail of a request is
     * here as soon as the request is answered.
     */
    protected function mails(): InMemoryMailer
    {
        return $this->mailer;
    }

    /**
     * From now on the cron job does not run by itself: mails stay in the outbox until
     * deliverMail() is called. For tests about the outbox, and about what happens to a mail that
     * waits.
     */
    protected function holdMail(): void
    {
        $this->cronRunsAfterEachRequest = false;
    }

    /**
     * Runs the sender of the outbox once, as `mail:send` does, with the clock of the test.
     */
    protected function deliverMail(): MailSendReport
    {
        $sender = $this->service(SendQueuedMails::class);

        $this->mailServer->cronIsRunning = true;
        try {
            return $sender->execute();
        } finally {
            $this->mailServer->cronIsRunning = false;
        }
    }

    /**
     * The mail server of the tests: set its $failure to let it refuse every mail.
     */
    protected function mailServer(): CronOnlyMailer
    {
        return $this->mailServer;
    }

    /**
     * The only mail to the address since the last call of mails()->clear(). Fails when there is
     * none or more than one.
     */
    protected function mailTo(string $address): Mail
    {
        $mails = array_values(array_filter(
            $this->mailer->sent(),
            static fn (Mail $mail): bool => $mail->to->value === $address,
        ));
        self::assertCount(1, $mails, "Expected exactly one mail to {$address}.");

        return $mails[0];
    }

    /**
     * The token of the link in a confirmation or reset mail.
     */
    protected static function tokenIn(Mail $mail): string
    {
        self::assertSame(1, preg_match('~\?token=([A-Za-z0-9_-]+)\s~', $mail->text, $match), 'The mail has no link with a token.');

        return $match[1] ?? '';
    }

    /**
     * Lets the following requests come from another client address (REMOTE_ADDR), e.g. to test the
     * rate limit per client. A new test starts with CLIENT_ADDRESS again.
     */
    protected function fromClientAddress(string $address): void
    {
        $this->clientAddress = $address;
    }

    /**
     * Stores an account directly, without registration and without a mail. Its password is
     * TestAccounts::PASSWORD (stored as a ready-made Argon2id hash, so this costs no hashing time).
     *
     * @param bool $verified False for a registration that is not confirmed yet.
     */
    protected function givenUser(string $email = 'anna@example.org', bool $verified = true, Locale $locale = Locale::De): User
    {
        $user = TestAccounts::user($this->service(IdGenerator::class)->next(), $email, $verified ? $this->clock->now() : null, $this->clock->now(), $locale);
        $this->service(UserRepository::class)->add($user);

        return $user;
    }

    /**
     * The headers of a request by this user: a valid access token, issued by the application under
     * test with its clock. This is how a test acts as a logged-in user:
     *
     *     $anna = $this->givenUser();
     *     $response = $this->call('GET', '/me', headers: $this->authorization($anna));
     *
     * @return array{Authorization: string}
     */
    protected function authorization(User|UserId $user): array
    {
        // Issued for the account as it is stored now: the token version counts password resets.
        $id = $user instanceof User ? $user->id : $user;
        $stored = $this->service(UserRepository::class)->find($id);
        $token = $this->service(AccessTokenIssuer::class)->issue($id, $stored === null ? 0 : $stored->tokenVersion);

        return ['Authorization' => 'Bearer ' . $token->token];
    }

    /**
     * Logs in over HTTP, as a client does, and returns the response (accessToken, refreshToken or
     * the cookie). Slower than authorization(): the password is really checked.
     */
    protected function logIn(string $email = 'anna@example.org', string $password = TestAccounts::PASSWORD, string $refreshIn = 'body'): ApiResponse
    {
        $response = $this->call('POST', '/auth/login', ['email' => $email, 'password' => $password, 'refreshIn' => $refreshIn]);
        self::assertSame(200, $response->status(), 'Login failed: ' . $response->body());

        return $response;
    }

    /**
     * A valid body for POST /auth/verify-email: the token of the confirmation link and the password
     * of the sign-up.
     *
     * @return array{token: string, password: string, refreshIn: string}
     */
    protected static function confirmation(string $token, string $password = TestAccounts::PASSWORD, string $refreshIn = 'body'): array
    {
        return ['token' => $token, 'password' => $password, 'refreshIn' => $refreshIn];
    }

    /**
     * Confirms a registration over HTTP, as the confirmation page does, and returns the response:
     * the session of the user who is logged in by it.
     */
    protected function confirmEmail(string $token, string $password = TestAccounts::PASSWORD, string $refreshIn = 'body'): ApiResponse
    {
        $response = $this->call('POST', '/auth/verify-email', self::confirmation($token, $password, $refreshIn));
        self::assertSame(200, $response->status(), 'Confirming failed: ' . $response->body());

        return $response;
    }

    /**
     * The container of the application under test, to fetch a use case or a repository from.
     */
    protected function container(): ContainerInterface
    {
        $this->app();
        \assert($this->container !== null);

        return $this->container;
    }

    /**
     * A service of the application under test, with its type.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->container()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * Sends a request that the contract allows and checks the response against the contract.
     *
     * @param array<string, mixed>|null $json    The JSON object to send as the body, or null for no body.
     * @param array<string, string>     $headers E.g. ['Authorization' => 'Bearer …'].
     */
    protected function call(string $method, string $path, ?array $json = null, array $headers = []): ApiResponse
    {
        $request = $this->request($method, $path, $json, $headers);
        $contract = Contract::fromFile($this->contractFile());

        $operation = $contract->operation($request);
        if ($operation === null) {
            self::fail(
                Contract::describe($request) . ' is not in the contract. Add the operation to ' . $contract->file
                . ' first, or use callUndocumented() for a path that is deliberately not part of the API.',
            );
        }
        $violation = $contract->requestViolation($request);
        if ($violation !== null) {
            self::fail(
                'The request ' . Contract::describe($request) . ' violates the contract: ' . $violation
                . ' Use callInvalid() when that is the point of the test.',
            );
        }

        $response = $this->send($request);

        $violation = $contract->responseViolation($operation, $response->response);
        if ($violation !== null) {
            self::fail($this->responseViolates($request, $response, $violation));
        }
        self::assertStatusIsConsistent($request, $response);

        return $response;
    }

    /**
     * Sends a request that the contract forbids (missing field, wrong type, malformed JSON, no token)
     * and checks that the response is documented for the operation all the same.
     *
     * @param array<string, mixed>|string|null $body    A JSON object, or the raw body text, or null for no body.
     * @param array<string, string>            $headers
     */
    protected function callInvalid(string $method, string $path, array|string|null $body = null, array $headers = []): ApiResponse
    {
        $request = $this->request($method, $path, $body, $headers);
        $contract = Contract::fromFile($this->contractFile());

        $operation = $contract->operation($request);
        if ($operation === null) {
            self::fail(
                Contract::describe($request) . ' is not in the contract. Add the operation to ' . $contract->file
                . ' first, or use callUndocumented() for a path that is deliberately not part of the API.',
            );
        }
        if ($contract->requestViolation($request) === null) {
            self::fail(
                'The request ' . Contract::describe($request) . ' is valid by the contract. Use call(), or tighten '
                . 'the contract if the server is right to reject this request.',
            );
        }

        $response = $this->send($request);

        $violation = $contract->responseViolation($operation, $response->response);
        if ($violation !== null) {
            self::fail($this->responseViolates($request, $response, $violation));
        }
        self::assertStatusIsConsistent($request, $response);

        return $response;
    }

    /**
     * Sends a request to a path or method that is deliberately not in the contract: an unknown route,
     * a test-only route. An error response must be RFC 9457 problem details as the contract defines them.
     *
     * @param array<string, mixed>|string|null $body     A JSON object, or the raw body text, or null for no body.
     * @param array<string, string>            $headers
     * @param string                           $basePath '' to address a path outside /api/v1.
     */
    protected function callUndocumented(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        string $basePath = Routes::BASE_PATH,
    ): ApiResponse {
        $request = $this->request($method, $path, $body, $headers, $basePath);
        $contract = Contract::fromFile($this->contractFile());

        if ($contract->operation($request) !== null) {
            self::fail(
                Contract::describe($request) . ' is in the contract. Use call() or callInvalid(), so that the '
                . 'request and the response are checked against it.',
            );
        }

        $response = $this->send($request);

        if ($response->status() >= 400) {
            $problem = $response->json();
            $schema = ($problem['type'] ?? null) === 'validation_failed' ? 'ValidationProblem' : 'Problem';
            $violation = $contract->schemaViolation($schema, $problem);
            if ($violation !== null) {
                self::fail($this->responseViolates($request, $response, "not a valid {$schema}: {$violation}"));
            }
        }
        self::assertStatusIsConsistent($request, $response);

        return $response;
    }

    /**
     * Asserts status and type of a problem response and returns its body.
     *
     * @return array<string, mixed>
     */
    protected static function assertProblem(int $status, string $type, ApiResponse $response): array
    {
        $problem = $response->json();

        self::assertSame(
            ['status' => $status, 'type' => $type, 'contentType' => 'application/problem+json'],
            [
                'status' => $response->status(),
                'type' => $problem['type'] ?? null,
                'contentType' => $response->header('Content-Type'),
            ],
            'Unexpected response: ' . $response->body(),
        );
        self::assertSame($status, $problem['status'] ?? null);

        return $problem;
    }

    /**
     * Field and code of every error of a 422 body, in order. The message is English text for
     * developers and not part of what a test pins down: it only has to be there.
     *
     * @param array<string, mixed> $problem
     *
     * @return list<array{field: mixed, code: mixed}>
     */
    protected static function fieldCodes(array $problem): array
    {
        $errors = $problem['errors'] ?? null;
        self::assertIsArray($errors, 'The problem has no list of errors.');

        $fieldCodes = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            self::assertSame(['field', 'code', 'message'], array_keys($error));
            self::assertIsString($error['message']);
            self::assertNotSame('', $error['message']);
            $fieldCodes[] = ['field' => $error['field'], 'code' => $error['code']];
        }

        return $fieldCodes;
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        if ($this->app !== null) {
            return $this->app;
        }

        $this->container = (new ContainerFactory())->create($this->settings(), $this->services() + [
            // Time stands still, nothing is written to a log file and no mail leaves the process,
            // unless the test says otherwise.
            ClockInterface::class => $this->clock,
            LoggerInterface::class => $this->logger,
            // Takes mail only while deliverMail() runs the sender. See the class comment.
            Mailer::class => $this->mailServer,
            TransportInterface::class => $this->smtp,
            // The real outbox, plus a count of what was queued.
            MailOutbox::class => decorate(function (MailOutbox $outbox): MailOutbox {
                return $this->outbox = new CountingMailOutbox($outbox);
            }),
        ]);
        $app = (new AppFactory())->create($this->container);
        $this->app = $app;
        // After the assignment: routes() may ask for a service, which asks for the application.
        $this->routes($app);

        return $app;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    private function request(
        string $method,
        string $path,
        array|string|null $body,
        array $headers,
        string $basePath = Routes::BASE_PATH,
    ): ServerRequestInterface {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest(
            $method,
            'https://app.example.test' . $basePath . $path,
            ['REMOTE_ADDR' => $this->clientAddress],
        );

        if ($body !== null) {
            $text = is_string($body)
                ? $body
                : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $request = $request
                ->withBody($factory->createStream($text))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Content-Length', (string) strlen($text));
        }
        foreach ($headers as $name => $value) {
            $request = $value === '' ? $request->withoutHeader($name) : $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Runs the request through the application and checks what holds for every response of this API,
     * whatever the contract says about the operation.
     */
    private function send(ServerRequestInterface $request): ApiResponse
    {
        $app = $this->app();
        $queuedBefore = $this->outbox === null ? 0 : $this->outbox->added;
        $response = new ApiResponse($app->handle($request));
        $about = 'Response to ' . Contract::describe($request) . ' (' . $response->status() . ')';

        // A request never talks to a mail server: how long that takes, and whether it works, must
        // not show in any response (FR-ACC-01, FR-ACC-05).
        self::assertSame([], $this->mailServer->sentByARequest, Contract::describe($request) . ' sent mail itself instead of queuing it.');
        self::assertSame(0, $this->smtp->calls, Contract::describe($request) . ' touched the SMTP transport.');
        // The cron job, a minute later: only after a request that queued something, so a request
        // that has nothing to do with mail leaves the outbox (and a test's rows in it) alone.
        if ($this->cronRunsAfterEachRequest && $this->outbox !== null && $this->outbox->added > $queuedBefore) {
            $this->deliverMail();
        }

        self::assertSame('no-store', $response->header('Cache-Control'), "{$about} may be cached.");
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'), "{$about} lacks the security headers.");
        if ($response->status() >= 400) {
            self::assertSame(
                'application/problem+json',
                $response->header('Content-Type'),
                "{$about} is an error that is not sent as problem details: " . $response->body(),
            );
        }

        return $response;
    }

    /**
     * What the contract cannot say, for every response. Runs after the contract check, so that a
     * response that breaks the contract is reported as such first.
     */
    private static function assertStatusIsConsistent(ServerRequestInterface $request, ApiResponse $response): void
    {
        $about = 'Response to ' . Contract::describe($request) . ' (' . $response->status() . ')';

        if ($response->status() >= 400) {
            // The contract only says that "status" is an integer. A client may read either one, so the
            // two must never disagree (RFC 9457: "status" is the status code of the response).
            self::assertSame(
                $response->status(),
                $response->json()['status'] ?? null,
                "{$about} carries another status in its problem body: " . $response->body(),
            );
        }
        if ($response->status() === 204) {
            self::assertSame('', $response->body(), "{$about} is a 204 with a body.");
        }
    }

    private function responseViolates(ServerRequestInterface $request, ApiResponse $response, string $violation): string
    {
        return 'The response to ' . Contract::describe($request) . ' (' . $response->status() . ' '
            . $response->header('Content-Type') . ') violates the contract ' . Contract::fromFile($this->contractFile())->file
            . ': ' . $violation . ' Body: ' . $response->body();
    }
}
