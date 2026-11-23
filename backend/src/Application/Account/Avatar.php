<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * One of the preset avatars (FR-ACC-01). There are no uploads, so nothing needs moderation (SRS §6).
 *
 * The keys are neutral numbers until the artwork exists. A key that was released must stay in the
 * list, because users have it stored.
 */
final readonly class Avatar
{
    /** @var list<string> */
    public const array PRESETS = [
        'avatar-01', 'avatar-02', 'avatar-03', 'avatar-04', 'avatar-05', 'avatar-06',
        'avatar-07', 'avatar-08', 'avatar-09', 'avatar-10', 'avatar-11', 'avatar-12',
    ];

    private function __construct(public string $key)
    {
    }

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        if (!in_array($input, self::PRESETS, true)) {
            throw new InvalidValue(ErrorCode::InvalidValue, 'Must be one of the preset avatars.');
        }

        return new self($input);
    }
}
