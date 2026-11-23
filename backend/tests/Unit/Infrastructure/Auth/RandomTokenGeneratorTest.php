<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Auth;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Infrastructure\Auth\RandomTokenGenerator;
use SmokeDetector\Infrastructure\Auth\UuidV7Generator;
use SmokeDetector\Infrastructure\Clock\FrozenClock;

/**
 * Where tokens and ids come from (HLD §4.5: 32 random bytes as base64url; §4.3: UUID version 7).
 */
final class RandomTokenGeneratorTest extends TestCase
{
    public function test_NFR_05_a_token_is_32_random_bytes_as_base64url_without_padding(): void
    {
        $token = (new RandomTokenGenerator())->generate();

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $bytes = base64_decode(strtr($token, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(32, strlen($bytes));
    }

    public function test_NFR_05_tokens_do_not_repeat_and_use_the_whole_alphabet(): void
    {
        $generator = new RandomTokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 500; $i++) {
            $tokens[] = $generator->generate();
        }

        self::assertCount(500, array_unique($tokens));
        // 21,500 characters: each of the 64 is there, unless something cuts the randomness down.
        self::assertCount(64, array_unique(str_split(implode('', $tokens))));
        foreach ($tokens as $token) {
            self::assertSame(43, strlen($token));
            self::assertStringNotContainsString('=', $token);
        }
    }

    public function test_an_id_is_a_uuid_version_7_in_lower_case_that_starts_with_the_time_of_the_clock(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');
        $id = (new UuidV7Generator($clock))->next();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id);
        // The first 48 bits are the Unix time in milliseconds.
        $milliseconds = $clock->now()->getTimestamp() * 1000;
        self::assertSame(sprintf('%012x', $milliseconds), str_replace('-', '', substr($id, 0, 13)));
    }

    public function test_ids_do_not_repeat_and_later_ids_sort_after_earlier_ones(): void
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');
        $generator = new UuidV7Generator($clock);

        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = $generator->next();
        }
        $clock->advanceSeconds(1);
        $later = $generator->next();

        self::assertCount(200, array_unique($ids));
        $sorted = $ids;
        sort($sorted);
        // Within one millisecond the ids still count upwards: new rows go to the end of the index.
        self::assertSame($sorted, $ids);
        self::assertGreaterThan($ids[199], $later);
    }
}
