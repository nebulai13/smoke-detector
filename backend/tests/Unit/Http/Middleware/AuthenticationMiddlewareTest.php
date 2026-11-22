<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Middleware;

use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\Authenticate;
use SmokeDetector\Config\Secret;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Middleware\AuthenticationMiddleware;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Infrastructure\Auth\JwtAccessTokens;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\Fake\CapturingRequestHandler;
use SmokeDetector\Tests\Support\Fake\SequenceTokenGenerator;
use SmokeDetector\Tests\Support\InMemory\InMemoryUserRepository;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * The gate in front of every endpoint that needs a logged-in user (HLD §4.5, NFR-05).
 */
final class AuthenticationMiddlewareTest extends TestCase
{
    private const string SECRET = 'unit-test-jwt-secret-0123456789-abcdefghijklmnopqrstuvwxyz';
    private const string USER = '019a9279-2f6c-7b3e-8d1a-0123456789ab';

    private FrozenClock $clock;

    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->clock = FrozenClock::at('2026-11-17 12:00:00');
        $this->users = new InMemoryUserRepository();
        $this->users->add(TestAccounts::user(self::USER, 'anna@example.org', $this->clock->now(), $this->clock->now()));
    }

    public function test_NFR_05_a_valid_access_token_lets_the_request_through_and_names_the_user(): void
    {
        $expected = new Response(204);
        $handler = new CapturingRequestHandler($expected);
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;

        $response = $this->middleware()->process(self::request("Bearer {$token}"), $handler);

        self::assertSame($expected, $response);
        self::assertInstanceOf(ServerRequestInterface::class, $handler->request);
        self::assertSame(self::USER, AuthenticatedUser::id($handler->request)->value);
    }

    public function test_the_scheme_is_accepted_in_any_case(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;

        foreach (['bearer', 'BEARER', 'bEaReR'] as $scheme) {
            $handler = new CapturingRequestHandler(new Response(204));
            $this->middleware()->process(self::request("{$scheme} {$token}"), $handler);
            self::assertNotNull($handler->request);
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function badHeaders(): iterable
    {
        yield 'no header' => [null];
        yield 'empty header' => [''];
        yield 'only the scheme' => ['Bearer'];
        yield 'scheme and space' => ['Bearer '];
        yield 'the token without a scheme' => ['{token}'];
        yield 'another scheme' => ['Basic {token}'];
        yield 'basic credentials' => ['Basic ' . base64_encode('anna@example.org:password')];
        yield 'two spaces' => ['Bearer  {token}'];
        yield 'a tab' => ["Bearer\t{token}"];
        // (Space around the whole value is not part of a header value, RFC 9110, and is not tested.)
        yield 'two tokens' => ['Bearer {token} {token}'];
        yield 'token in quotes' => ['Bearer "{token}"'];
        yield 'two credentials in one header' => ['Bearer {token}, Bearer {token}'];
        yield 'not a JWT' => ['Bearer abc'];
        yield 'characters no JWT has' => ['Bearer a.b.c+/='];
        yield 'a tampered token' => ['Bearer {token}x'];
    }

    #[DataProvider('badHeaders')]
    public function test_NFR_05_a_request_without_a_usable_bearer_token_is_refused_with_the_same_401(?string $header): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        $handler = new CapturingRequestHandler(new Response(204));

        $this->assertRefused(self::request($header === null ? null : str_replace('{token}', $token, $header)), $handler);
    }

    public function test_NFR_05_an_expired_token_is_refused(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        $this->clock->advanceSeconds(900);

        $this->assertRefused(self::request("Bearer {$token}"), new CapturingRequestHandler(new Response(204)));
    }

    public function test_FR_ACC_08_the_token_of_a_deleted_account_is_refused_like_any_bad_token(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        $this->users->delete(UserId::fromString(self::USER));

        $this->assertRefused(self::request("Bearer {$token}"), new CapturingRequestHandler(new Response(204)));
    }

    public function test_NFR_05_a_token_signed_with_another_secret_is_refused(): void
    {
        $foreign = (new JwtAccessTokens(new Secret(str_repeat('z', 40)), $this->clock, new SequenceTokenGenerator()))
            ->issue(UserId::fromString(self::USER), 0)->token;

        $this->assertRefused(self::request("Bearer {$foreign}"), new CapturingRequestHandler(new Response(204)));
    }

    public function test_NFR_05_an_unsigned_token_and_a_token_with_another_algorithm_are_refused(): void
    {
        $claims = ['sub' => self::USER, 'iat' => $this->clock->now()->getTimestamp(), 'exp' => $this->clock->now()->getTimestamp() + 900, 'jti' => 'x'];
        $payload = self::base64Url((string) json_encode($claims));

        $none = self::base64Url('{"typ":"JWT","alg":"none"}') . ".{$payload}.";
        $this->assertRefused(self::request("Bearer {$none}"), new CapturingRequestHandler(new Response(204)));

        $header = self::base64Url('{"typ":"JWT","alg":"HS512"}');
        $hs512 = "{$header}.{$payload}." . self::base64Url(hash_hmac('sha512', "{$header}.{$payload}", self::SECRET, true));
        $this->assertRefused(self::request("Bearer {$hs512}"), new CapturingRequestHandler(new Response(204)));
    }

    public function test_NFR_05_two_authorization_headers_are_refused_even_when_one_is_valid(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        $request = self::request("Bearer {$token}")->withAddedHeader('Authorization', 'Bearer something-else');

        $this->assertRefused($request, new CapturingRequestHandler(new Response(204)));
    }

    public function test_NFR_05_a_token_anywhere_else_than_the_header_is_not_looked_at(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        $request = (new ServerRequest('GET', "/api/v1/me?access_token={$token}"))
            ->withQueryParams(['access_token' => $token])
            ->withCookieParams(['access_token' => $token])
            ->withParsedBody(['access_token' => $token]);

        $this->assertRefused($request, new CapturingRequestHandler(new Response(204)));
    }

    public function test_an_action_that_is_not_behind_the_middleware_cannot_ask_for_the_user(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This route is not behind the AuthenticationMiddleware.');

        AuthenticatedUser::id(new ServerRequest('GET', '/api/v1/me'));
    }

    public function test_NFR_05_a_client_cannot_name_the_user_itself(): void
    {
        // A request attribute cannot come from outside, but whatever arrives under the same name
        // in headers, query or body must not count.
        $request = (new ServerRequest('GET', '/api/v1/me?authenticatedUserId=' . self::USER, ['authenticatedUserId' => self::USER]))
            ->withQueryParams(['authenticatedUserId' => self::USER])
            ->withParsedBody(['authenticatedUserId' => self::USER])
            ->withAttribute('authenticatedUserId', self::USER);

        $this->expectException(LogicException::class);

        AuthenticatedUser::id($request);
    }

    private function assertRefused(ServerRequestInterface $request, CapturingRequestHandler $handler): void
    {
        try {
            $this->middleware()->process($request, $handler);
            self::fail('Expected the request to be refused.');
        } catch (ProblemException $refused) {
            // The same for every reason, and nothing of the reason travels with it.
            self::assertSame(
                ['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401],
                $refused->problem->toArray(),
            );
            self::assertSame(['WWW-Authenticate' => 'Bearer'], $refused->problem->headers);
            self::assertNull($refused->getPrevious());
        }
        self::assertNull($handler->request, 'The action must not run.');
    }

    private function middleware(): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(new Authenticate($this->tokens(), $this->users));
    }

    private function tokens(): JwtAccessTokens
    {
        return new JwtAccessTokens(new Secret(self::SECRET), $this->clock, new SequenceTokenGenerator());
    }

    private static function request(?string $authorization): ServerRequest
    {
        $request = new ServerRequest('GET', '/api/v1/me');

        return $authorization === null ? $request : $request->withHeader('Authorization', $authorization);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
