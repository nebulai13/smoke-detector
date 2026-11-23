<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SensitiveParameter;
use SmokeDetector\Application\Mail\MailValuesCipher;
use SmokeDetector\Application\Mail\MailValuesUnreadable;

/**
 * "Encryption" for unit tests: the values as hexadecimal digits of their JSON, between "sealed("
 * and ")". Not readable at a glance, so a test can tell that a row does not hold a link in plain
 * text, and predictable, so a test can make such a row itself (seal()).
 */
final class FakeMailValuesCipher implements MailValuesCipher
{
    public function seal(#[SensitiveParameter] array $values): string
    {
        return 'sealed(' . bin2hex(json_encode($values, JSON_THROW_ON_ERROR)) . ')';
    }

    public function open(string $sealed): array
    {
        if (preg_match('/\Asealed\(((?:[0-9a-f]{2})*)\)\z/', $sealed, $match) !== 1) {
            // Like the real cipher: a fixed message, so the text that could not be read travels nowhere.
            throw new MailValuesUnreadable();
        }

        $values = [];
        foreach ((array) json_decode((string) hex2bin($match[1]), true, 4, JSON_THROW_ON_ERROR) as $name => $value) {
            \assert(is_string($value));
            $values[(string) $name] = $value;
        }

        return $values;
    }
}
