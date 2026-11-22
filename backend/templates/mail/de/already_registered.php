<?php

declare(strict_types=1);

// Registration, or a request for a new confirmation link, with the address of an existing account:
// says that the account exists, points to login and password reset, and changes nothing (SRS
// FR-ACC-01). The text must fit both requests.
return [
    'subject' => 'Du hast schon ein Konto bei Smoke Detector',
    'blocks' => [
        'Hey!',
        // "Vor Kurzem", not "gerade": the mail is sent a minute or two after the request, and later
        // when the mail server had to be tried again.
        'Für diese E-Mail-Adresse gibt es bei Smoke Detector bereits ein Konto: deins. Vor Kurzem wurde mit '
        . 'ihr eine Registrierung oder ein neuer Bestätigungslink angefordert, und beides braucht es nicht mehr.',
        'Falls du das warst, melde dich einfach an:',
        ['link' => 'loginUrl', 'label' => 'Anmelden'],
        'Passwort vergessen? Kein Problem, hier kannst du ein neues setzen:',
        ['link' => 'resetUrl', 'label' => 'Passwort zurücksetzen'],
        'Du warst das nicht? Dann musst du nichts tun. An deinem Konto wurde nichts geändert, und '
        . 'niemand hat Zugriff darauf bekommen.',
        'Viele Grüße' . "\n" . 'Dex von Smoke Detector',
    ],
];
