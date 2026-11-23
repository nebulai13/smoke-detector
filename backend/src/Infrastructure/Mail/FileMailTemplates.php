<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use InvalidArgumentException;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\MailTemplates;

/**
 * Mail templates as PHP files: <directory>/<locale>/<template>.php (see templates/mail/README.md).
 *
 * A template is a subject and a list of blocks: paragraphs of plain text, and links that name a
 * value of the mail. The plain-text part and the HTML part are both built from these blocks, so
 * they always say the same. Everything is escaped for HTML: neither a template nor a value can
 * bring markup into a mail.
 *
 * Template and call must fit exactly (every link has its value, every value its link). A mail that
 * would go out with a missing link is a bug that must show up as an error, not in a user's inbox.
 */
final readonly class FileMailTemplates implements MailTemplates
{
    public function __construct(
        /** The directory with one sub-directory per language. */
        private string $directory,
    ) {
    }

    public function render(string $template, Locale $locale, EmailAddress $to, array $values): Mail
    {
        if (preg_match('/\A[a-z]+(?:_[a-z]+)*\z/D', $template) !== 1) {
            throw new InvalidArgumentException('A mail template name is made of a-z and "_".');
        }
        foreach ($values as $name => $value) {
            // A value is a link. No message here repeats it: it carries the token.
            if (preg_match('~\Ahttps?://[^\s\p{Cc}]+\z~Du', $value) !== 1) {
                throw new InvalidArgumentException("The value \"{$name}\" of a mail must be an http(s) URL.");
            }
        }

        [$subject, $blocks] = $this->load($template, $locale);

        $text = [];
        $html = [];
        $used = [];
        foreach ($blocks as $block) {
            if (is_string($block)) {
                $text[] = $block;
                $html[] = '<p>' . str_replace("\n", "<br>\n", self::escape($block)) . '</p>';

                continue;
            }

            $url = $values[$block['link']]
                ?? throw new InvalidArgumentException("The mail template \"{$template}\" needs the value \"{$block['link']}\".");
            $used[] = $block['link'];

            $text[] = $block['label'] . ":\n" . $url;
            $html[] = '<p><a href="' . self::escape($url) . '" style="display:inline-block;padding:12px 20px;'
                . 'background:#0B7A5E;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:bold">'
                . self::escape($block['label']) . '</a></p>';
            // Again as text, for mail programs that do not show links.
            $html[] = '<p style="font-size:13px;color:#5B6B75;word-break:break-all">' . self::escape($url) . '</p>';
        }
        foreach (array_keys($values) as $name) {
            if (!in_array($name, $used, true)) {
                throw new InvalidArgumentException("The mail template \"{$template}\" does not use the value \"{$name}\".");
            }
        }

        return new Mail(
            $to,
            $template,
            $subject,
            implode("\n\n", $text) . "\n",
            self::page($locale, $subject, implode("\n", $html)),
        );
    }

    /**
     * @return array{string, non-empty-list<string|array{link: string, label: string}>} Subject and blocks.
     */
    private function load(string $template, Locale $locale): array
    {
        $file = "{$this->directory}/{$locale->value}/{$template}.php";
        if (!is_file($file)) {
            throw new InvalidArgumentException("There is no mail template \"{$template}\" for \"{$locale->value}\".");
        }

        $content = require $file;
        if (
            !is_array($content)
            || array_keys($content) !== ['subject', 'blocks']
            || !is_string($content['subject'])
            || !is_array($content['blocks'])
        ) {
            throw new InvalidArgumentException(
                "The mail template \"{$template}\" must return an array with \"subject\" and \"blocks\".",
            );
        }
        // The subject becomes a mail header: a line break in it would start another header.
        if (preg_match('/\A[^\p{Cc}]{1,150}\z/Du', $content['subject']) !== 1) {
            throw new InvalidArgumentException("The subject of the mail template \"{$template}\" must be one line of text.");
        }

        $blocks = [];
        foreach (array_values($content['blocks']) as $index => $block) {
            if (is_string($block) && $block !== '') {
                $blocks[] = $block;

                continue;
            }

            $link = is_array($block) && array_keys($block) === ['link', 'label'] ? $block['link'] : null;
            $label = is_array($block) && array_keys($block) === ['link', 'label'] ? $block['label'] : null;
            if (!is_string($link) || $link === '' || !is_string($label) || $label === '') {
                throw new InvalidArgumentException(
                    "Block {$index} of the mail template \"{$template}\" must be a text or a link with \"link\" and \"label\".",
                );
            }
            $blocks[] = ['link' => $link, 'label' => $label];
        }
        if ($blocks === []) {
            throw new InvalidArgumentException("The mail template \"{$template}\" has no blocks.");
        }

        return [$content['subject'], $blocks];
    }

    /**
     * Simple on purpose: inline styles only, no images, nothing loaded from anywhere. Mail programs
     * strip most of what a web page could do, and a mail that loads nothing tracks nobody.
     */
    private static function page(Locale $locale, string $subject, string $body): string
    {
        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . $locale->value . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::escape($subject) . '</title>' . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:24px;background:#F5FBF9;color:#1C2A33;'
            . 'font-family:Nunito,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5">' . "\n"
            . '<div style="max-width:560px;margin:0 auto">' . "\n"
            . $body . "\n"
            . '</div>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
