<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Config;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Config\Secret;

/**
 * A secret hands its value out only through reveal(), so it cannot slip into a log or a dump.
 */
final class SecretTest extends TestCase
{
    private const string VALUE = 'LEAK-correct-horse-battery-staple';

    public function test_reveal_returns_the_value(): void
    {
        self::assertSame(self::VALUE, (new Secret(self::VALUE))->reveal());
    }

    public function test_an_empty_secret_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A secret must not be empty.');

        new Secret('');
    }

    public function test_dumping_a_secret_does_not_show_the_value(): void
    {
        $secret = new Secret(self::VALUE);

        ob_start();
        var_dump($secret);
        $dumped = (string) ob_get_clean();

        self::assertStringNotContainsString('LEAK', $dumped);
        self::assertStringContainsString('[secret]', $dumped);
        self::assertStringNotContainsString('LEAK', print_r($secret, true));
        self::assertSame('{}', json_encode($secret, JSON_THROW_ON_ERROR));
    }

    public function test_dumping_an_object_that_holds_a_secret_does_not_show_the_value(): void
    {
        $holder = new class (new Secret(self::VALUE)) {
            public function __construct(public Secret $password)
            {
            }
        };

        self::assertStringNotContainsString('LEAK', print_r($holder, true));
    }

    public function test_a_secret_cannot_be_serialized(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A secret cannot be serialized.');

        serialize(new Secret(self::VALUE));
    }

    public function test_the_stack_trace_of_a_failing_caller_does_not_show_the_value(): void
    {
        try {
            new Secret('');
        } catch (InvalidArgumentException $exception) {
            // The parameter is marked sensitive, so even a non-empty value would be replaced in the trace.
            self::assertStringContainsString('SensitiveParameterValue', $exception->getTraceAsString());
        }
    }
}
