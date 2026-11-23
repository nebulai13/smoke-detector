<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Auth;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Infrastructure\Auth\Argon2idPasswordHasher;

/**
 * Password hashing (NFR-05: Argon2id; HLD §4.5: rehash when the parameters change).
 */
final class Argon2idPasswordHasherTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    /** A hash of PASSWORD with the current parameters, made once: hashing takes its time on purpose. */
    private static string $hash;

    public static function setUpBeforeClass(): void
    {
        self::$hash = (new Argon2idPasswordHasher())->hash(self::PASSWORD);
    }

    public function test_NFR_05_a_password_is_hashed_with_argon2id_and_the_fixed_parameters(): void
    {
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', self::$hash);
        self::assertSame(
            ['algo' => 'argon2id', 'algoName' => 'argon2id', 'options' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]],
            password_get_info(self::$hash),
        );
        self::assertStringNotContainsString(self::PASSWORD, self::$hash);
        // Fits the password_hash column.
        self::assertLessThanOrEqual(255, strlen(self::$hash));
    }

    public function test_NFR_05_the_right_password_verifies_and_a_wrong_one_does_not(): void
    {
        $hasher = new Argon2idPasswordHasher();

        self::assertTrue($hasher->verify(self::PASSWORD, self::$hash));
        self::assertFalse($hasher->verify('correct horse battery stapl', self::$hash));
        self::assertFalse($hasher->verify('', self::$hash));
    }

    public function test_NFR_05_a_password_is_never_trimmed_cut_or_changed_in_case(): void
    {
        $hasher = new Argon2idPasswordHasher();

        self::assertFalse($hasher->verify(' ' . self::PASSWORD, self::$hash));
        self::assertFalse($hasher->verify(self::PASSWORD . ' ', self::$hash));
        self::assertFalse($hasher->verify(self::PASSWORD . "\n", self::$hash));
        self::assertFalse($hasher->verify(strtoupper(self::PASSWORD), self::$hash));
        // Nothing is cut off at a NUL byte or after 72 bytes, as bcrypt would.
        self::assertFalse($hasher->verify(self::PASSWORD . "\0x", self::$hash));

        $long = str_repeat('🔥', 199);
        $hash = $hasher->hash($long . 'a');
        self::assertTrue($hasher->verify($long . 'a', $hash));
        self::assertFalse($hasher->verify($long . 'b', $hash));
        self::assertFalse($hasher->verify($long, $hash));
    }

    public function test_NFR_05_the_same_password_gives_a_different_hash_each_time(): void
    {
        $again = (new Argon2idPasswordHasher())->hash(self::PASSWORD);

        // A random salt per hash: equal passwords are not visible in the database.
        self::assertNotSame(self::$hash, $again);
        self::assertTrue((new Argon2idPasswordHasher())->verify(self::PASSWORD, $again));
    }

    public function test_NFR_05_a_hash_with_the_current_parameters_needs_no_rehash(): void
    {
        self::assertFalse((new Argon2idPasswordHasher())->needsRehash(self::$hash));
    }

    public function test_NFR_05_a_hash_with_other_parameters_or_another_algorithm_needs_a_rehash_and_still_verifies(): void
    {
        $hasher = new Argon2idPasswordHasher();
        $outdated = [
            'less memory' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 4, 'threads' => 1]),
            'fewer passes' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]),
            'argon2i' => password_hash(self::PASSWORD, PASSWORD_ARGON2I, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            // The fallback of NFR-05: a hash made where Argon2id was missing is upgraded at the next login.
            'bcrypt' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        ];

        foreach ($outdated as $what => $hash) {
            self::assertTrue($hasher->needsRehash($hash), $what);
            self::assertTrue($hasher->verify(self::PASSWORD, $hash), $what);
            self::assertFalse($hasher->verify('wrong password', $hash), $what);
        }
    }

    public function test_something_that_is_not_a_hash_verifies_nothing(): void
    {
        $hasher = new Argon2idPasswordHasher();

        foreach (['', 'not-a-hash', self::PASSWORD, '$argon2id$v=19$m=65536,t=4,p=1$broken'] as $hash) {
            self::assertFalse($hasher->verify(self::PASSWORD, $hash), $hash);
            self::assertFalse($hasher->verify('', $hash), $hash);
        }
        // (A damaged hash that still starts with the right parameters is not reported as outdated.
        // It does not matter: nothing verifies against it, and only a verified password is rehashed.)
        foreach (['', 'not-a-hash', self::PASSWORD] as $hash) {
            self::assertTrue($hasher->needsRehash($hash), $hash);
        }
    }

    public function test_NFR_05_the_dummy_hash_costs_as_much_as_a_real_one_and_matches_no_password(): void
    {
        $hasher = new Argon2idPasswordHasher();
        $dummy = $hasher->dummyHash();

        // Same algorithm and parameters as a real hash, so checking against it takes as long.
        self::assertSame(password_get_info(self::$hash), password_get_info($dummy));
        self::assertFalse($hasher->needsRehash($dummy));
        self::assertSame(strlen(self::$hash), strlen($dummy));
        self::assertSame($dummy, $hasher->dummyHash());

        foreach (['', self::PASSWORD, 'password', 'dummy', $dummy] as $password) {
            self::assertFalse($hasher->verify($password, $dummy));
        }
    }
}
