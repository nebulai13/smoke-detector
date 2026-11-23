# Mail templates

One file per mail and language: `<locale>/<template>.php` (`de`, `en`). The file name is the value
of `SmokeDetector\Application\Mail\MailKind`. Rendered by
`SmokeDetector\Infrastructure\Mail\FileMailTemplates`, which builds the plain-text part and the HTML
part from the same blocks, so the two cannot drift apart.

A template returns:

```php
return [
    'subject' => 'One line, no placeholders',
    'blocks' => [
        'A paragraph of plain text.',
        ['link' => 'confirmUrl', 'label' => 'What the link says'],   // 'link' names a value of the mail
    ],
];
```

Rules (checked by `FileMailTemplatesTest`):

- Both languages have the same templates, with the same links in the same order.
- Every value the application passes must be used by a link, and every link must name a passed value.
- Plain text only: no HTML and no placeholders inside paragraphs. Text is escaped for the HTML part.
- Tone as in `docs/BRAND.md`: casual (German: du), warm, never preachy.
- Nothing the person who filled in a form typed ever goes into a mail (no names): a stranger can
  make us send these mails to any address.
- No text says or implies that the mail arrives the moment something was asked for ("just now",
  "gerade"). A mail is queued by the request and sent by the cron job: a minute or two later, and
  up to an hour and a half later when the mail server had to be tried again. The time a link is
  valid counts from the request, not from the arrival of the mail.
