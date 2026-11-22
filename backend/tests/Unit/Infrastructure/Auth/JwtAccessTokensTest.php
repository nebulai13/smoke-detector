<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\Auth\JwtAccessTokens;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\Fake\SequenceTokenGenerator;

/**
 * The access token (HLD §4.5: JWT, HS256, 15 minutes, claims sub, iat, exp, jti; NFR-05).
 */
final class JwtAccessTokensTest extends TestCase
{
    private const string SECRET = 'unit-test-jwt-secret-0123456789-abcdefghijklmnopqrstuvwxyz';
    private const string USER = '019a9279-2f6c-7b3e-8d1a-0123456789ab';
    /** 2026-11-17 12:00:00 UTC */
    private const int NOW = 1_794_916_800;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = FrozenClock::at('2026-11-17 12:00:00');
        self::assertSame(self::NOW, $this->clock->now()->getTimestamp());
    }

    public function test_NFR_05_an_access_token_is_a_jwt_signed_with_hs256(): void
    {
        $issued = $this->tokens()->issue(UserId::fromString(self::USER), 0);

        $parts = explode('.', $issued->token);
        self::assertCount(3, $parts);
        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], self::decode($parts[0]));
        // HMAC-SHA256 over "header.payload" with the secret of the settings.
        self::assertSame(
            self::base64Url(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", self::SECRET, true)),
            $parts[2],
        );
    }

    public function test_NFR_05_an_access_token_carries_sub_iat_exp_jti_and_ver_and_lives_15_minutes(): void
    {
        $issued = $this->tokens()->issue(UserId::fromString(self::USER), 3);

        self::assertSame(
            ['sub' => self::USER, 'iat' => self::NOW, 'exp' => self::NOW + 900, 'jti' => 'token-1', 'ver' => 3],
            self::decode(explode('.', $issued->token)[1]),
        );
        self::assertSame(900, $issued->expiresInSeconds);
    }

    public function test_NFR_05_every_access_token_has_its_own_jti(): void
    {
        $tokens = $this->tokens();

        $first = $tokens->issue(UserId::fromString(self::USER), 0);
        $second = $tokens->issue(UserId::fromString(self::USER), 0);

        self::assertNotSame($first->token, $second->token);
        self::assertSame('token-2', self::decode(explode('.', $second->token)[1])['jti'] ?? null);
    }

    public function test_NFR_05_a_token_we_issued_names_its_user(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;

        self::assertSame(self::USER, $this->tokens()->verify($token)->userId->value);
    }

    public function test_NFR_05_the_time_comes_from_the_injected_clock_and_a_token_expires_after_15_minutes(): void
    {
        $token = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;

        $this->clock->advanceSeconds(899);
        self::assertSame(self::USER, $this->tokens()->verify($token)->userId->value);

        $this->clock->advanceSeconds(1);
        $this->assertRefused($token);

        // Far in the future and far in the past of the real time: only the clock counts.
        $this->clock->travelTo(new \DateTimeImmutable('2031-01-01 00:00:00 UTC'));
        $future = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        self::assertSame(self::USER, $this->tokens()->verify($future)->userId->value);
        $this->clock->travelTo(new \DateTimeImmutable('2021-01-01 00:00:00 UTC'));
        $past = $this->tokens()->issue(UserId::fromString(self::USER), 0)->token;
        self::assertSame(self::USER, $this->tokens()->verify($past)->userId->value);
    }

    public function test_NFR_05_verifying_leaves_no_time_behind_in_the_library(): void
    {
        $tokens = $this->tokens();
        $tokens->verify($tokens->issue(UserId::fromString(self::USER), 0)->token);
        $this->assertRefused('garbage');

        self::assertNull(\Firebase\JWT\JWT::$timestamp);
    }

    public function test_NFR_05_a_token_signed_with_another_secret_is_refused(): void
    {
        $foreign = (new JwtAccessTokens(new Secret(str_repeat('x', 48)), $this->clock, new SequenceTokenGenerator()))
            ->issue(UserId::fromString(self::USER), 0)->token;

        $this->assertRefused($foreign);
        $this->assertRefused(self::sign(self::claims(), 'HS256', self::SECRET . 'x'));
    }

    public function test_NFR_05_a_token_that_was_changed_after_signing_is_refused(): void
    {
        [$header, $payload, $signature] = explode('.', $this->tokens()->issue(UserId::fromString(self::USER), 0)->token);
        $otherUser = self::base64Url((string) json_encode(['sub' => '019a9279-2f6c-7b3e-8d1a-ffffffffffff'] + self::claims()));

        $this->assertRefused("{$header}.{$otherUser}.{$signature}");
        $this->assertRefused("{$header}.{$payload}." . strrev($signature));
        $this->assertRefused("{$header}.{$payload}.");
        $this->assertRefused("{$header}.{$payload}");
    }

    public function test_NFR_05_an_unsigned_token_with_alg_none_is_refused(): void
    {
        $payload = self::base64Url((string) json_encode(self::claims()));

        foreach (['none', 'None', 'NONE', 'nOnE'] as $alg) {
            $header = self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => $alg]));
            $this->assertRefused("{$header}.{$payload}.");
            $this->assertRefused("{$header}.{$payload}");
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherAlgorithms(): iterable
    {
        yield 'HS384 with our secret' => ['HS384', 'sha384'];
        yield 'HS512 with our secret' => ['HS512', 'sha512'];
        // The classic confusion: an asymmetric algorithm in the header, an HMAC as the "signature".
        yield 'RS256 in the header, HMAC signature' => ['RS256', 'sha256'];
        yield 'ES256 in the header, HMAC signature' => ['ES256', 'sha256'];
        yield 'EdDSA in the header, HMAC signature' => ['EdDSA', 'sha256'];
        yield 'an unknown algorithm' => ['HS1', 'sha256'];
        yield 'no algorithm' => ['', 'sha256'];
    }

    #[DataProvider('otherAlgorithms')]
    public function test_NFR_05_a_token_with_any_algorithm_other_than_hs256_is_refused(string $alg, string $hash): void
    {
        $this->assertRefused(self::sign(self::claims(), $alg, self::SECRET, $hash));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badClaims(): iterable
    {
        $claims = self::claims();

        yield 'no sub' => [array_diff_key($claims, ['sub' => 0])];
        yield 'no iat' => [array_diff_key($claims, ['iat' => 0])];
        yield 'no exp (would never expire)' => [array_diff_key($claims, ['exp' => 0])];
        yield 'no jti' => [array_diff_key($claims, ['jti' => 0])];
        yield 'no ver' => [array_diff_key($claims, ['ver' => 0])];
        yield 'ver is text' => [['ver' => '0'] + $claims];
        yield 'ver is a float' => [['ver' => 0.5] + $claims];
        yield 'ver is negative' => [['ver' => -1] + $claims];
        yield 'ver is null' => [['ver' => null] + $claims];
        yield 'no claims' => [[]];
        yield 'sub is not a UUID' => [['sub' => 'admin'] + $claims];
        yield 'sub is an upper-case UUID' => [['sub' => strtoupper(self::USER)] + $claims];
        yield 'sub is a number' => [['sub' => 42] + $claims];
        yield 'sub is a list' => [['sub' => [self::USER]] + $claims];
        yield 'sub is null' => [['sub' => null] + $claims];
        yield 'jti is empty' => [['jti' => ''] + $claims];
        yield 'jti is a number' => [['jti' => 7] + $claims];
        yield 'exp is text' => [['exp' => 'tomorrow'] + $claims];
        yield 'exp is a numeric string' => [['exp' => (string) (self::NOW + 900)] + $claims];
        yield 'exp is a float' => [['exp' => self::NOW + 900.5] + $claims];
        yield 'exp is null' => [['exp' => null] + $claims];
        yield 'iat is text' => [['iat' => 'now'] + $claims];
        yield 'iat is a numeric string' => [['iat' => (string) self::NOW] + $claims];
        yield 'expired a second ago' => [['iat' => self::NOW - 901, 'exp' => self::NOW - 1] + $claims];
        yield 'expires this second' => [['iat' => self::NOW - 900, 'exp' => self::NOW] + $claims];
        yield 'issued in the future' => [['iat' => self::NOW + 1, 'exp' => self::NOW + 901] + $claims];
        yield 'not valid yet' => [['nbf' => self::NOW + 60] + $claims];
        yield 'lives longer than 15 minutes' => [['exp' => self::NOW + 901] + $claims];
        yield 'expires before it was issued' => [['iat' => self::NOW, 'exp' => self::NOW - 1] + $claims];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('badClaims')]
    public function test_NFR_05_a_correctly_signed_token_without_the_required_claims_is_refused(array $claims): void
    {
        $this->assertRefused(self::sign($claims, 'HS256', self::SECRET));
    }

    public function test_FR_ACC_05_a_verified_token_names_the_token_version_it_was_issued_under(): void
    {
        $tokens = $this->tokens();

        self::assertSame(0, $tokens->verify($tokens->issue(UserId::fromString(self::USER), 0)->token)->tokenVersion);
        self::assertSame(7, $tokens->verify($tokens->issue(UserId::fromString(self::USER), 7)->token)->tokenVersion);
        self::assertSame(self::USER, $tokens->verify($tokens->issue(UserId::fromString(self::USER), 7)->token)->userId->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headersTheLibraryChokesOn(): iterable
    {
        // The JWT library does not refuse these with one of its own exceptions: it runs into a
        // TypeError (or an InvalidArgumentException for the key). Nobody needs the secret to send one.
        yield 'alg is a list' => ['{"typ":"JWT","alg":["HS256"]}'];
        yield 'alg is an object' => ['{"typ":"JWT","alg":{"a":"HS256"}}'];
        yield 'alg is a number' => ['{"typ":"JWT","alg":256}'];
        yield 'alg is null' => ['{"typ":"JWT","alg":null}'];
        yield 'kid is a list' => ['{"typ":"JWT","alg":"HS256","kid":["a"]}'];
        yield 'kid is an object' => ['{"typ":"JWT","alg":"HS256","kid":{"a":1}}'];
    }

    #[DataProvider('headersTheLibraryChokesOn')]
    public function test_NFR_05_a_header_that_makes_the_jwt_library_fail_with_a_php_error_is_refused_like_any_other(string $header): void
    {
        $payload = self::base64Url((string) json_encode(self::claims()));
        $unsigned = self::base64Url($header) . '.' . $payload;

        $this->assertRefused($unsigned . '.' . self::base64Url(hash_hmac('sha256', $unsigned, self::SECRET, true)));
        $this->assertRefused($unsigned . '.x');
    }

    public function test_the_handmade_token_of_these_tests_is_accepted_when_its_claims_are_right(): void
    {
        // Otherwise the tests above could pass for the wrong reason.
        self::assertSame(self::USER, $this->tokens()->verify(self::sign(self::claims(), 'HS256', self::SECRET))->userId->value);
        self::assertSame(
            self::USER,
            $this->tokens()->verify(self::sign(['iat' => self::NOW - 899, 'exp' => self::NOW + 1] + self::claims(), 'HS256', self::SECRET))->userId->value,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'one part' => ['abc'];
        yield 'two parts' => ['abc.def'];
        yield 'four parts' => ['a.b.c.d'];
        yield 'not base64url' => ['***.***.***'];
        yield 'not JSON' => [self::base64Url('x') . '.' . self::base64Url('y') . '.' . self::base64Url('z')];
        yield 'header is a list' => [self::base64Url('[]') . '.' . self::base64Url('{}') . '.' . self::base64Url('z')];
        yield 'payload is a list' => [self::base64Url('{"typ":"JWT","alg":"HS256"}') . '.' . self::base64Url('[1]') . '.x'];
        yield 'only dots' => ['..'];
        yield 'with spaces' => [' a.b.c '];
    }

    #[DataProvider('malformedTokens')]
    public function test_NFR_05_something_that_is_not_a_jwt_is_refused(string $token): void
    {
        $this->assertRefused($token);
    }

    public function test_NFR_05_every_refusal_looks_the_same(): void
    {
        $messages = [];
        foreach (['', 'a.b.c', self::sign(self::claims(), 'HS256', 'another-secret-another-secret-another-secret'), self::sign(['exp' => 1] + self::claims(), 'HS256', self::SECRET)] as $token) {
            try {
                $this->tokens()->verify($token);
                self::fail('Expected the token to be refused.');
            } catch (InvalidAccessToken $refused) {
                $messages[] = $refused->getMessage();
                self::assertNull($refused->getPrevious());
            }
        }

        self::assertSame(['The access token is invalid.'], array_values(array_unique($messages)));
    }

    private function tokens(): JwtAccessTokens
    {
        return new JwtAccessTokens(new Secret(self::SECRET), $this->clock, new SequenceTokenGenerator());
    }

    private function assertRefused(string $token): void
    {
        try {
            $this->tokens()->verify($token);
            self::fail('Expected the token to be refused.');
        } catch (InvalidAccessToken) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(): array
    {
        return ['sub' => self::USER, 'iat' => self::NOW, 'exp' => self::NOW + 900, 'jti' => 'handmade', 'ver' => 0];
    }

    /**
     * A token made by hand, without the library under test.
     *
     * @param array<string, mixed> $claims
     */
    private static function sign(array $claims, string $alg, string $secret, string $hash = 'sha256'): string
    {
        $header = self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $payload = self::base64Url((string) json_encode($claims === [] ? new \stdClass() : $claims));

        return "{$header}.{$payload}." . self::base64Url(hash_hmac($hash, "{$header}.{$payload}", $secret, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $part): array
    {
        $json = json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);
        self::assertIsArray($json);

        return $json;
    }
}
