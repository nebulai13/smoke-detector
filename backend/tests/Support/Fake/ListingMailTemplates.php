<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use RuntimeException;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\MailTemplates;

/**
 * "Templates" for unit tests: the mail just lists what it was made from, so a test can read the
 * language and the links back. Subject "<template> [<locale>]", text one "name=value" per line.
 */
final class ListingMailTemplates implements MailTemplates
{
    /**
     * @var list<string> Templates that cannot be rendered, as in a release that was uploaded
     *                   without one of its files. The failure names the address on purpose: a test
     *                   can then tell that the message of the failure is not logged.
     */
    public array $broken = [];

    public function render(string $template, Locale $locale, EmailAddress $to, array $values): Mail
    {
        if (in_array($template, $this->broken, true)) {
            throw new RuntimeException("The template {$template} is missing; the mail to {$to->value} cannot be made.");
        }

        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = "{$name}={$value}";
        }
        $text = implode("\n", $lines);

        return new Mail($to, $template, "{$template} [{$locale->value}]", $text, '<pre>' . htmlspecialchars($text) . '</pre>');
    }
}
