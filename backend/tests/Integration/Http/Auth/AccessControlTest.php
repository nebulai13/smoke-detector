<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Auth;

use PHPUnit\Framework\AssertionFailedError;
use Slim\App;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Config\Secret;
use SmokeDetector\Http\Input\TokenField;
use SmokeDetector\Http\Middleware\AuthenticationMiddleware;
use SmokeDetector\Infrastructure\Auth\JwtAccessTokens;
use SmokeDetector\Tests\Support\Fake\SequenceTokenGenerator;
use SmokeDetector\Tests\Support\Fake\WhoAmIEndpoint;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use Symfony\Component\Yaml\Yaml;

/**
 * The bearer token check in the real application (HLD §4.5, NFR-05), and that the routes are on
 * the side of it that the contract says.
 *
 * A test-only route stands behind the middleware here, so that these tests are about the token
 * check alone. The real protected endpoints (/me, …) have their own tests.
 */
final class AccessControlTest extends HttpTestCase
{
    protected function routes(App $app): void
    {
        $app->get('/test/whoami', new WhoAmIEndpoint())->add(AuthenticationMiddleware::class);
    }

    public function test_NFR_05_a_request_with_the_access_token_of_a_login_reaches_the_action_as_that_user(): void
    {
        $anna = $this->givenUser();
        $token = $this->logIn()->json()['accessToken'] ?? null;
        self::assertIsString($token);

        $response = $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => "Bearer {$token}"]);

        self::assertSame(200, $response->status());
        self::assertSame(['userId' => $anna->id->value], $response->json());
    }

    public function test_the_test_helper_gives_the_headers_of_a_logged_in_user(): void
    {
        $anna = $this->givenUser('anna@example.org');
        $bob = $this->givenUser('bob@example.org');

        self::assertSame(['userId' => $anna->id->value], $this->callUndocumented('GET', '/test/whoami', null, $this->authorization($anna))->json());
        self::assertSame(['userId' => $bob->id->value], $this->callUndocumented('GET', '/test/whoami', null, $this->authorization($bob->id))->json());
    }

    public function test_NFR_05_an_access_token_works_for_15_minutes_by_the_applications_clock(): void
    {
        $headers = $this->authorization($this->givenUser());

        $this->clock()->advanceSeconds(899);
        self::assertSame(200, $this->callUndocumented('GET', '/test/whoami', null, $headers)->status());

        $this->clock()->advanceSeconds(1);
        $this->assertUnauthorized($this->callUndocumented('GET', '/test/whoami', null, $headers));
    }

    public function test_NFR_05_missing_malformed_expired_and_forged_tokens_all_get_the_same_401(): void
    {
        $anna = $this->givenUser();
        $now = $this->clock()->now()->getTimestamp();
        $claims = ['sub' => $anna->id->value, 'iat' => $now, 'exp' => $now + 900, 'jti' => 'x'];
        $secret = $this->settings()->jwtSecret->reveal();
        $valid = $this->authorization($anna)['Authorization'];
        self::assertSame(200, $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => $valid])->status());

        $expired = self::jwt(['iat' => $now - 1000, 'exp' => $now - 100] + $claims, 'HS256', $secret);
        $otherSecret = (new JwtAccessTokens(new Secret(str_repeat('k', 48)), $this->clock(), new SequenceTokenGenerator()))->issue($anna->id, 0)->token;
        $none = self::base64Url('{"typ":"JWT","alg":"none"}') . '.' . self::base64Url((string) json_encode($claims)) . '.';
        $refreshToken = $this->logIn()->json()['refreshToken'] ?? null;
        self::assertIsString($refreshToken);

        $responses = [
            'no header' => $this->callUndocumented('GET', '/test/whoami'),
            'empty token' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Bearer']),
            'not a JWT' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Bearer not-a-jwt']),
            'basic auth' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Basic ' . base64_encode('anna@example.org:x')]),
            'the token without the scheme' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => substr($valid, 7)]),
            'tampered' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => $valid . 'x']),
            'expired' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => "Bearer {$expired}"]),
            'signed with another secret' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => "Bearer {$otherSecret}"]),
            'alg none' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => "Bearer {$none}"]),
            'HS512 with our secret' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Bearer ' . self::jwt($claims, 'HS512', $secret)]),
            'RS256 in the header' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Bearer ' . self::jwt($claims, 'RS256', $secret)]),
            'no exp claim' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => 'Bearer ' . self::jwt(array_diff_key($claims, ['exp' => 0]), 'HS256', $secret)]),
            'a refresh token' => $this->callUndocumented('GET', '/test/whoami', null, ['Authorization' => "Bearer {$refreshToken}"]),
        ];

        foreach ($responses as $name => $response) {
            $this->assertUnauthorized($response, $name);
            // Status, every header and the body: nothing says what was wrong with the token.
            self::assertSame(
                [$responses['no header']->status(), $responses['no header']->body(), $responses['no header']->response->getHeaders()],
                [$response->status(), $response->body(), $response->response->getHeaders()],
                $name,
            );
        }
        // And nothing about a token is logged.
        self::assertSame([], $this->logs()->records);
    }

    public function test_NFR_05_a_token_is_only_accepted_in_the_authorization_header(): void
    {
        $token = substr($this->authorization($this->givenUser())['Authorization'], 7);

        $this->assertUnauthorized($this->callUndocumented('GET', "/test/whoami?access_token={$token}"));
        $this->assertUnauthorized($this->callUndocumented('GET', '/test/whoami', null, ['Cookie' => "access_token={$token}", 'X-Access-Token' => $token]));
    }

    public function test_NFR_05_every_operation_that_needs_a_token_by_the_contract_refuses_a_request_without_one(): void
    {
        foreach (self::operations() as $name => [$method, $path, $public]) {
            $response = $this->callUnchecked($method, $path);

            if ($public) {
                $type = $response->status() === 204 ? null : ($response->json()['type'] ?? null);
                self::assertNotSame('unauthorized', $type, "{$name} is public in the contract but asks for a token.");
            } else {
                $this->assertUnauthorized($response, "{$name} needs a token by the contract but answers without one");
            }
        }
        self::assertNotSame([], self::operations());
    }

    public function test_the_public_operations_are_exactly_health_the_consent_versions_and_the_auth_operations(): void
    {
        // Making an operation public is a decision, not an accident: a new one has to be added here.
        $public = array_keys(array_filter(self::operations(), static fn (array $operation): bool => $operation[2]));

        self::assertSame(
            [
                'GET /health',
                'GET /legal/consents',
                'POST /auth/register',
                'POST /auth/verify-email',
                'POST /auth/resend-verification',
                'POST /auth/login',
                'POST /auth/refresh',
                'POST /auth/logout',
                'POST /auth/password-reset/request',
                'POST /auth/password-reset/confirm',
            ],
            $public,
        );
    }

    public function test_the_contract_states_the_same_limits_as_the_code(): void
    {
        $schema = static fn (string ...$keys): mixed => self::at(self::document(), 'components', 'schemas', ...$keys);

        self::assertSame(Avatar::PRESETS, $schema('AvatarKey', 'enum'));
        self::assertSame(PasswordPolicy::MIN_LENGTH, $schema('NewPassword', 'minLength'));
        self::assertSame(PasswordPolicy::MAX_LENGTH, $schema('NewPassword', 'maxLength'));
        self::assertSame(PasswordPolicy::MAX_LENGTH, $schema('LoginRequest', 'properties', 'password', 'maxLength'));
        self::assertSame(DeviceLabel::MAX_LENGTH, $schema('LoginRequest', 'properties', 'deviceLabel', 'maxLength'));
        self::assertSame(['de', 'en'], $schema('Locale', 'enum'));
        self::assertSame(DisplayName::MIN_LENGTH, $schema('DisplayName', 'minLength'));
        self::assertSame(DisplayName::MAX_LENGTH, $schema('DisplayName', 'maxLength'));
        self::assertSame(254, $schema('EmailAddress', 'maxLength'));
        self::assertSame(TokenField::MAX_LENGTH, $schema('VerifyEmailRequest', 'properties', 'token', 'maxLength'));
        self::assertSame(PasswordPolicy::MAX_LENGTH, $schema('VerifyEmailRequest', 'properties', 'password', 'maxLength'));
        self::assertSame(DeviceLabel::MAX_LENGTH, $schema('VerifyEmailRequest', 'properties', 'deviceLabel', 'maxLength'));
        self::assertSame(TokenField::MAX_LENGTH, $schema('PasswordResetConfirmRequest', 'properties', 'token', 'maxLength'));
        self::assertSame(TokenField::MAX_LENGTH, $schema('RefreshTokenRequest', 'properties', 'refreshToken', 'maxLength'));
    }

    private function assertUnauthorized(ApiResponse $response, string $message = ''): void
    {
        self::assertSame(401, $response->status(), $message);
        self::assertSame(['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401], $response->json(), $message);
        self::assertSame('Bearer', $response->header('WWW-Authenticate'), $message);
        self::assertSame('application/problem+json', $response->header('Content-Type'), $message);
    }

    /**
     * Sends a bare request (no body, no token) to an operation of the contract. For some operations
     * that is a valid request and for others not, and the harness wants to be told which.
     */
    private function callUnchecked(string $method, string $path): ApiResponse
    {
        try {
            return $this->call($method, $path);
        } catch (AssertionFailedError) {
            return $this->callInvalid($method, $path);
        }
    }

    /**
     * @return array<string, array{string, string, bool}> "GET /health" => [method, path with sample
     *                                                    values for its parameters, public?].
     */
    private static function operations(): array
    {
        $paths = self::at(self::document(), 'paths');
        self::assertIsArray($paths);

        $operations = [];
        foreach ($paths as $path => $item) {
            self::assertIsArray($item);
            foreach (['get', 'put', 'post', 'delete', 'patch'] as $method) {
                if (isset($item[$method])) {
                    $operations[strtoupper($method) . ' ' . $path] = [
                        strtoupper($method),
                        (string) preg_replace('/\{[^}]+\}/', '00000000-0000-7000-8000-000000000001', (string) $path),
                        self::at($item, $method, 'security') === [],
                    ];
                }
            }
        }

        return $operations;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function document(): array
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);

        return $document;
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function at(array $node, string ...$keys): mixed
    {
        $value = $node;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A token made by hand, signed with an HMAC whatever the header claims.
     *
     * @param array<string, mixed> $claims
     */
    private static function jwt(array $claims, string $alg, string $secret): string
    {
        $header = self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $payload = self::base64Url((string) json_encode($claims));
        $hash = $alg === 'HS512' ? 'sha512' : 'sha256';

        return "{$header}.{$payload}." . self::base64Url(hash_hmac($hash, "{$header}.{$payload}", $secret, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
