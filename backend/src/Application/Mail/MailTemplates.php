<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;

/**
 * Turns a named template into a mail in the user's language (FR-I18N-01).
 */
interface MailTemplates
{
    /**
     * @param string                $template The template's name, e.g. "confirm_email".
     * @param array<string, string> $values   What the template's placeholders stand for, e.g.
     *                                        ['confirmUrl' => 'https://…'].
     */
    public function render(string $template, Locale $locale, EmailAddress $to, array $values): Mail;
}
