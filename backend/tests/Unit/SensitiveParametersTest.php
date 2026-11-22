<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SensitiveParameter;
use SmokeDetector\Tests\Support\Paths;
use SplFileInfo;

/**
 * A stack trace lists the arguments of every call in it. PHP leaves an argument out when its
 * parameter is marked #[SensitiveParameter], and only then: the attribute is not inherited, so it
 * has to stand on the method that really runs, not just on the interface it implements.
 */
final class SensitiveParametersTest extends TestCase
{
    /** A string parameter with one of these words in its name carries a password, a token or a hash of one. */
    private const string SECRET_NAME = '/password|token|secret|hash/i';

    public function test_NFR_05_every_parameter_that_carries_a_password_or_token_is_kept_out_of_stack_traces(): void
    {
        $unmarked = [];
        $checked = 0;
        foreach (self::classes() as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string' || preg_match(self::SECRET_NAME, $parameter->getName()) !== 1) {
                        continue;
                    }
                    $checked++;
                    if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                        $unmarked[] = "{$class}::{$method->getName()}(\${$parameter->getName()})";
                    }
                }
            }
        }

        self::assertSame([], $unmarked, 'String parameters named like a secret without #[SensitiveParameter].');
        // The search itself works: there are dozens of such parameters.
        self::assertGreaterThan(40, $checked);
    }

    public function test_a_marked_argument_is_really_left_out_of_a_trace(): void
    {
        // What the attribute buys, shown once: the value does not appear, a placeholder does.
        $trace = (static function (#[SensitiveParameter] string $password): array {
            return (new \RuntimeException('x'))->getTrace();
        })('hunter2-the-real-password');

        self::assertStringNotContainsString('hunter2', print_r($trace, true));
        // (With zend.exception_ignore_args=On, as in production, a trace has no arguments at all.)
        if (isset($trace[0]['args'])) {
            self::assertInstanceOf(\SensitiveParameterValue::class, $trace[0]['args'][0] ?? null);
        }
    }

    /**
     * @return list<class-string> Every class, interface and enum under src/.
     */
    private static function classes(): array
    {
        $src = Paths::backend() . '/src';
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'SmokeDetector\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
            self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), "{$class} cannot be loaded.");
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }
}
