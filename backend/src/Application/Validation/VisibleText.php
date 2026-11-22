<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Validation;

/**
 * The rules for short text that other people get to see as it is: a display name, a device label.
 */
final readonly class VisibleText
{
    /**
     * Characters such text must not contain:
     *
     * - control characters and line breaks, which would break lists and mails,
     * - the bidirectional overrides, isolates and marks, which let a text rearrange what is shown
     *   next to it,
     * - every other format character (zero-width space, word joiner, soft hyphen, byte order mark,
     *   …): nobody sees them, so a name made of them shows as nothing, and one of them inside a
     *   name makes a second "Anna" that cannot be told from the first.
     *
     * The zero-width joiner is the one exception, and only where it joins emoji into one
     * (astronaut = woman + joiner + rocket): after an emoji, a skin tone or a variation selector,
     * and before an emoji.
     */
    private const string FORBIDDEN = '/[\p{Cc}\x{2028}\x{2029}]|[^\P{Cf}\x{200D}]'
        . '|(?<![\p{So}\p{Sk}\x{FE0F}])\x{200D}|\x{200D}(?!\p{So})/u';

    /**
     * What can be seen: letters, digits, punctuation and symbols. Not the "letters" and "symbols"
     * that are drawn as nothing: the Hangul fillers, the empty Braille pattern and the musical
     * null notehead.
     */
    private const string VISIBLE = '/(?![\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}\x{1D159}])[\p{L}\p{N}\p{P}\p{S}]/u';

    /**
     * Whether the text is not valid UTF-8 or contains a character of the list above.
     */
    public static function hasForbiddenCharacters(string $text): bool
    {
        return preg_match('//u', $text) !== 1 || preg_match(self::FORBIDDEN, $text) === 1;
    }

    /**
     * @param string $text Valid UTF-8.
     *
     * @return int How many characters of the text can be seen.
     */
    public static function visibleCharacters(string $text): int
    {
        return (int) preg_match_all(self::VISIBLE, $text);
    }

    /**
     * The text without the spaces (of every kind) and tabs around it. A line break is not white
     * space to be dropped: it is a forbidden character wherever it stands.
     *
     * @param string $text Valid UTF-8. Anything else comes back unchanged, to be rejected.
     */
    public static function trimmed(string $text): string
    {
        // (preg_replace() only returns null for input it cannot read.)
        return preg_replace('/\A[\t\p{Zs}]+|[\t\p{Zs}]+\z/u', '', $text) ?? $text;
    }
}
