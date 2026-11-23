<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use SensitiveParameter;

/**
 * Encrypts the values of a queued mail (HLD §4.5 "Mail").
 *
 * The values are the links of the mail, and a confirmation or reset link carries its one-time token
 * in plain text. `one_time_tokens` deliberately holds only the hash of a token, so that reading the
 * database (a backup, an SQL injection somewhere) opens no account. The outbox must not undo that
 * for the minute a mail waits in it.
 */
interface MailValuesCipher
{
    /**
     * @param array<string, string> $values What the template's placeholders stand for.
     *
     * @return string Printable text that says nothing without the key. Never the same twice.
     */
    public function seal(#[SensitiveParameter] array $values): string;

    /**
     * @return array<string, string> The values that were sealed.
     *
     * @throws MailValuesUnreadable When the text is not what seal() made with the key in use
     *                              (damaged, or the key was changed since). The message never
     *                              repeats the text. The sender drops such a mail at once.
     */
    public function open(string $sealed): array;
}
