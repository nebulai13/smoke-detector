<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Mail;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Infrastructure\Mail\FileMailTemplates;
use SmokeDetector\Tests\Support\Fake\DeliveredAccountMails;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The mail templates of backend/templates/mail and how they become a mail (HLD §4.5 "Mail",
 * FR-I18N-01: German and English, chosen by the user's language).
 */
final class FileMailTemplatesTest extends TestCase
{
    private const string TOKEN = 'Zm9vYmFyLXRva2VuLTAxMjM0NTY3ODktQUJDREVGR0hJSktM';
    private const string FIXTURES = __DIR__ . '/fixtures';

    public function test_FR_ACC_01_the_german_confirmation_mail_is_casual_and_carries_the_link(): void
    {
        $mail = $this->accountMails()->confirmEmail(self::to(), Locale::De, self::TOKEN);
        $link = 'https://app.example.test/verify-email?token=' . self::TOKEN;

        self::assertSame('anna@example.org', $mail->to->value);
        self::assertSame('confirm_email', $mail->template);
        self::assertSame('Fast geschafft: Bestätige deine E-Mail-Adresse', $mail->subject);
        self::assertStringStartsWith("Hey!\n\nSchön, dass du bei Smoke Detector dabei bist.", $mail->text);
        self::assertStringContainsString("E-Mail-Adresse bestätigen:\n{$link}\n\n", $mail->text);
        self::assertStringContainsString('Der Link gilt 24 Stunden', $mail->text);
        self::assertStringContainsString('<a href="' . $link . '"', $mail->html);
        self::assertStringContainsString('<html lang="de">', $mail->html);
        // Casual: du, never Sie.
        self::assertDoesNotMatchRegularExpression('/\b(Sie|Ihre?[mnrs]?|Ihnen)\b/', $mail->text);
    }

    public function test_FR_ACC_01_the_english_confirmation_mail_carries_the_link(): void
    {
        $mail = $this->accountMails()->confirmEmail(self::to(), Locale::En, self::TOKEN);
        $link = 'https://app.example.test/verify-email?token=' . self::TOKEN;

        self::assertSame('Almost there: confirm your email address', $mail->subject);
        self::assertStringContainsString("Confirm email address:\n{$link}\n\n", $mail->text);
        self::assertStringContainsString('valid for 24 hours', $mail->text);
        self::assertStringContainsString('<a href="' . $link . '"', $mail->html);
        self::assertStringContainsString('<html lang="en">', $mail->html);
    }

    public function test_FR_ACC_03_the_confirmation_mail_says_that_the_page_behind_the_link_asks_for_the_password_of_the_sign_up(): void
    {
        $german = $this->accountMails()->confirmEmail(self::to(), Locale::De, self::TOKEN)->text;
        $english = $this->accountMails()->confirmEmail(self::to(), Locale::En, self::TOKEN)->text;

        self::assertStringContainsString(
            'Der Link öffnet eine Seite, die nach dem Passwort fragt, das du bei der Registrierung gewählt hast.',
            $german,
        );
        self::assertStringContainsString(
            'The link opens a page that asks for the password you chose when you signed up.',
            $english,
        );
        // Confirming logs in: the mails no longer send the user to the login afterwards.
        self::assertStringNotContainsString('meldest du dich einfach mit deinem Passwort an', $german);
        self::assertStringNotContainsString('simply log in with your password', $english);
        self::assertStringContainsString('Mit dem richtigen Passwort ist deine Adresse bestätigt und du bist direkt angemeldet.', $german);
        self::assertStringContainsString('With the right password your address is confirmed and you are logged in right away.', $english);
    }

    public function test_the_html_part_is_a_whole_document_in_the_language_of_the_mail_that_loads_nothing(): void
    {
        $german = $this->accountMails()->confirmEmail(self::to(), Locale::De, self::TOKEN);
        $english = $this->accountMails()->resetPassword(self::to(), Locale::En, self::TOKEN);

        foreach ([[$german, 'de'], [$english, 'en']] as [$mail, $language]) {
            $subject = htmlspecialchars($mail->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringStartsWith(
                "<!DOCTYPE html>\n<html lang=\"{$language}\">\n<head>\n<meta charset=\"utf-8\">\n"
                . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>{$subject}</title>\n</head>\n<body style=\"",
                $mail->html,
            );
            self::assertStringEndsWith("\n</div>\n</body>\n</html>\n", $mail->html);
            self::assertSame(1, preg_match('~</head>\n<body style="[^"<>]+">\n<div style="max-width:560px;margin:0 auto">\n<p~', $mail->html));
            // Nothing is loaded from anywhere: no image, script, stylesheet or font. The only URLs are the links.
            self::assertDoesNotMatchRegularExpression('/<(img|script|link|style|iframe|object|form)\b|url\(|@import/i', $mail->html);
            self::assertGreaterThan(0, preg_match_all('~https?://[^"<\s]+~', $mail->html, $urls));
            foreach ($urls[0] as $url) {
                self::assertStringStartsWith('https://app.example.test/', $url);
            }
        }
    }

    public function test_the_confirmation_mail_tells_a_stranger_the_truth_about_what_is_stored(): void
    {
        // The mail also reaches people whose address somebody else typed in. It must not tell them
        // that nothing is stored: the registration is, until it is deleted after 24 hours.
        $german = $this->accountMails()->confirmEmail(self::to(), Locale::De, self::TOKEN)->text;
        $english = $this->accountMails()->confirmEmail(self::to(), Locale::En, self::TOKEN)->text;

        self::assertStringNotContainsString('nichts gespeichert', $german);
        self::assertStringNotContainsString('Nothing is kept', $english);
        self::assertStringContainsString('Ohne Bestätigung wird das Konto nicht aktiviert, und die Registrierung wird nach 24 Stunden automatisch gelöscht.', $german);
        self::assertStringContainsString('Without the confirmation the account is never activated, and the registration is deleted automatically after 24 hours.', $english);
        // "Anmelden" is logging in, everywhere. The thing that expires is the "Registrierung".
        self::assertStringNotContainsString('Anmeldung', $german);
    }

    public function test_the_mails_say_what_happens_once_the_link_was_used_and_not_once_its_time_is_over(): void
    {
        // "Valid for 1 hour. After that you are signed out" reads as if the hour signed you out.
        $to = self::to();
        $texts = [
            $this->accountMails()->confirmEmail($to, Locale::De, self::TOKEN)->text,
            $this->accountMails()->confirmEmail($to, Locale::En, self::TOKEN)->text,
            $this->accountMails()->resetPassword($to, Locale::De, self::TOKEN)->text,
            $this->accountMails()->resetPassword($to, Locale::En, self::TOKEN)->text,
        ];

        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/einmal\. Danach|once\. After that/', $text);
        }
        self::assertStringContainsString('Sobald du ein neues Passwort gesetzt hast, wirst du auf allen Geräten abgemeldet', $texts[2]);
        self::assertStringContainsString('Once you have set the new password, you are signed out on all devices', $texts[3]);
    }

    public function test_the_already_registered_mail_fits_a_registration_and_a_request_for_a_new_link(): void
    {
        // It answers both "register" and "send the confirmation link again" for an address that has
        // an account, so it must not claim that somebody tried to register.
        $to = self::to();
        $german = $this->accountMails()->alreadyRegistered($to, Locale::De)->text;
        $english = $this->accountMails()->alreadyRegistered($to, Locale::En)->text;

        self::assertStringContainsString('gibt es bei Smoke Detector bereits ein Konto', $german);
        self::assertStringContainsString('There already is a Smoke Detector account for this email address', $english);
        self::assertStringNotContainsString('hat jemand versucht, sich', $german);
        self::assertStringNotContainsString('Someone just tried to sign up', $english);
    }

    public function test_no_mail_claims_to_arrive_the_moment_it_was_asked_for(): void
    {
        // A mail is sent by the cron job: a minute or two after the request, and up to an hour and
        // a half later when the mail server had to be tried again. "Just now" would be wrong then.
        $to = self::to();
        $mails = $this->accountMails();
        $texts = [];
        foreach (Locale::cases() as $locale) {
            $texts[] = $mails->confirmEmail($to, $locale, self::TOKEN)->text;
            $texts[] = $mails->alreadyRegistered($to, $locale)->text;
            $texts[] = $mails->resetPassword($to, $locale, self::TOKEN)->text;
        }

        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/\b(gerade|soeben|(was|were|has been|have been) just|just now|a moment ago|right now|seconds? ago)\b/i', $text);
        }
        self::assertStringContainsString('Vor Kurzem wurde mit ihr eine Registrierung oder ein neuer Bestätigungslink angefordert', $mails->alreadyRegistered($to, Locale::De)->text);
        self::assertStringContainsString('A sign-up or a new confirmation link was requested with it a short while ago', $mails->alreadyRegistered($to, Locale::En)->text);
    }

    #[DataProvider('locales')]
    public function test_FR_ACC_01_the_already_registered_mail_points_to_login_and_reset_and_carries_no_token(Locale $locale): void
    {
        $mail = $this->accountMails()->alreadyRegistered(self::to(), $locale);

        self::assertSame('already_registered', $mail->template);
        self::assertStringContainsString(":\nhttps://app.example.test/login\n\n", $mail->text);
        self::assertStringContainsString(":\nhttps://app.example.test/reset-password\n\n", $mail->text);
        self::assertStringContainsString('<a href="https://app.example.test/login"', $mail->html);
        self::assertStringContainsString('<a href="https://app.example.test/reset-password"', $mail->html);
        self::assertStringNotContainsString('token', $mail->text . $mail->html);
        self::assertSame(
            $locale === Locale::De ? 'Du hast schon ein Konto bei Smoke Detector' : 'You already have a Smoke Detector account',
            $mail->subject,
        );
    }

    #[DataProvider('locales')]
    public function test_FR_ACC_05_the_reset_mail_carries_the_link_and_says_how_long_it_is_valid(Locale $locale): void
    {
        $mail = $this->accountMails()->resetPassword(self::to(), $locale, self::TOKEN);
        $link = 'https://app.example.test/reset-password?token=' . self::TOKEN;

        self::assertSame('reset_password', $mail->template);
        self::assertStringContainsString(":\n{$link}\n\n", $mail->text);
        self::assertStringContainsString('<a href="' . $link . '"', $mail->html);
        self::assertStringContainsString($locale === Locale::De ? 'Der Link gilt 1 Stunde' : 'valid for 1 hour', $mail->text);
        self::assertSame($locale === Locale::De ? 'Neues Passwort für Smoke Detector' : 'New password for Smoke Detector', $mail->subject);
    }

    /**
     * @return iterable<string, array{Locale}>
     */
    public static function locales(): iterable
    {
        foreach (Locale::cases() as $locale) {
            yield $locale->value => [$locale];
        }
    }

    public function test_FR_I18N_01_every_mail_exists_in_every_language_with_the_same_links(): void
    {
        $mails = $this->accountMails();
        $perLocale = [];
        foreach (Locale::cases() as $locale) {
            foreach ([
                $mails->confirmEmail(self::to(), $locale, self::TOKEN),
                $mails->alreadyRegistered(self::to(), $locale),
                $mails->resetPassword(self::to(), $locale, self::TOKEN),
            ] as $mail) {
                preg_match_all('~https://\S+~', $mail->text, $links);
                $perLocale[$locale->value][$mail->template] = $links[0];
                self::assertNotSame([], $links[0], "{$mail->template} [{$locale->value}] has no link.");
            }
        }

        self::assertSame($perLocale['de'], $perLocale['en']);
        // And the texts are really different languages.
        self::assertNotSame(
            $mails->confirmEmail(self::to(), Locale::De, self::TOKEN)->text,
            $mails->confirmEmail(self::to(), Locale::En, self::TOKEN)->text,
        );
        // No template file without a mail that uses it, in either language.
        foreach (Locale::cases() as $locale) {
            $files = array_map(
                static fn (string $file): string => basename($file, '.php'),
                glob(Paths::backend() . "/templates/mail/{$locale->value}/*.php") ?: [],
            );
            sort($files);
            self::assertSame(['already_registered', 'confirm_email', 'reset_password'], $files);
        }
    }

    public function test_the_text_part_is_the_blocks_separated_by_empty_lines(): void
    {
        $mail = $this->fixtures()->render('sample', Locale::En, self::to(), ['firstUrl' => 'https://example.test/a?x=1&y=2']);

        self::assertSame('Subject with <b> & "quotes"', $mail->subject);
        self::assertSame(
            "First paragraph with <b>tags</b> & an ampersand.\n\n"
            . "The label:\nhttps://example.test/a?x=1&y=2\n\n"
            . "Two lines\nin one paragraph.\n",
            $mail->text,
        );
    }

    public function test_the_html_part_has_the_same_content_with_everything_escaped(): void
    {
        $mail = $this->fixtures()->render('sample', Locale::En, self::to(), ['firstUrl' => 'https://example.test/a?x=1&y=2']);

        self::assertStringStartsWith('<!DOCTYPE html>', $mail->html);
        self::assertStringContainsString('<html lang="en">', $mail->html);
        self::assertStringContainsString('<meta charset="utf-8">', $mail->html);
        self::assertStringContainsString('<title>Subject with &lt;b&gt; &amp; &quot;quotes&quot;</title>', $mail->html);
        self::assertStringContainsString('<p>First paragraph with &lt;b&gt;tags&lt;/b&gt; &amp; an ampersand.</p>', $mail->html);
        self::assertStringContainsString('<a href="https://example.test/a?x=1&amp;y=2"', $mail->html);
        self::assertStringContainsString('>The label</a>', $mail->html);
        self::assertStringContainsString("<p>Two lines<br>\nin one paragraph.</p>", $mail->html);
        // The text of the template never becomes markup.
        self::assertStringNotContainsString('<b>', $mail->html);
        // The link is also there as text, for mail programs that do not show links.
        self::assertStringContainsString('https://example.test/a?x=1&amp;y=2</', $mail->html);
    }

    public function test_a_value_cannot_inject_markup_or_a_second_link(): void
    {
        $mail = $this->fixtures()->render('sample', Locale::En, self::to(), ['firstUrl' => 'https://example.test/"><script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $mail->html);
        self::assertStringContainsString('href="https://example.test/&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $mail->html);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function brokenUses(): iterable
    {
        $url = ['firstUrl' => 'https://example.test/'];

        yield 'unknown template' => ['no_such_template', $url, 'There is no mail template "no_such_template" for "en".'];
        yield 'template name that is a path' => ['../en/sample', $url, 'A mail template name is made of a-z and "_".'];
        yield 'empty template name' => ['', $url, 'A mail template name is made of a-z and "_".'];
        yield 'a link without its value' => ['sample', [], 'The mail template "sample" needs the value "firstUrl".'];
        yield 'a value no link uses' => ['sample', $url + ['other' => 'https://example.test/other'], 'The mail template "sample" does not use the value "other".'];
        yield 'a value that is not a web link' => ['sample', ['firstUrl' => 'javascript:alert(1)'], 'The value "firstUrl" of a mail must be an http(s) URL.'];
        yield 'a value with a line break' => ['sample', ['firstUrl' => "https://example.test/\nBcc: x"], 'The value "firstUrl" of a mail must be an http(s) URL.'];
        yield 'template that returns no array' => ['not_an_array', [], 'The mail template "not_an_array" must return an array with "subject" and "blocks".'];
        yield 'template without subject' => ['no_subject', [], 'The mail template "no_subject" must return an array with "subject" and "blocks".'];
        yield 'template with an extra key' => ['extra_key', [], 'The mail template "extra_key" must return an array with "subject" and "blocks".'];
        yield 'template with a subject of two lines' => ['two_line_subject', [], 'The subject of the mail template "two_line_subject" must be one line of text.'];
        yield 'template without blocks' => ['no_blocks', [], 'The mail template "no_blocks" has no blocks.'];
        yield 'template with a broken block' => ['broken_block', [], 'Block 1 of the mail template "broken_block" must be a text or a link with "link" and "label".'];
        yield 'template with an empty paragraph' => ['empty_paragraph', [], 'Block 0 of the mail template "empty_paragraph" must be a text or a link with "link" and "label".'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('brokenUses')]
    public function test_a_template_or_a_call_that_does_not_fit_stops_instead_of_sending_a_broken_mail(string $template, array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->fixtures()->render($template, Locale::En, self::to(), $values);
    }

    public function test_a_language_without_the_template_stops_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no mail template "sample" for "de".');

        $this->fixtures()->render('sample', Locale::De, self::to(), ['firstUrl' => 'https://example.test/']);
    }

    public function test_no_message_about_a_template_repeats_a_value(): void
    {
        try {
            $this->fixtures()->render('sample', Locale::En, self::to(), ['firstUrl' => 'not-a-url-SECRET-TOKEN']);
            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('SECRET-TOKEN', $exception->getMessage());
        }
    }

    /**
     * The mails of the application as they arrive: queued with the values the application gives
     * them, then made from the real templates by the sender of the outbox.
     */
    private function accountMails(): DeliveredAccountMails
    {
        return new DeliveredAccountMails(new FileMailTemplates(Paths::backend() . '/templates/mail'), SettingsFixture::settings()->appUrl);
    }

    private function fixtures(): FileMailTemplates
    {
        return new FileMailTemplates(self::FIXTURES);
    }

    private static function to(): EmailAddress
    {
        return EmailAddress::fromInput('anna@example.org');
    }
}
