<?php

declare(strict_types=1);

// Registration: the link that confirms the email address (SRS FR-ACC-01, FR-ACC-03).
return [
    'subject' => 'Fast geschafft: Bestätige deine E-Mail-Adresse',
    'blocks' => [
        'Hey!',
        'Schön, dass du bei Smoke Detector dabei bist. Dex hat schon mal die Batterien geladen. '
        . 'Bestätige nur noch kurz deine E-Mail-Adresse, dann kann es losgehen:',
        ['link' => 'confirmUrl', 'label' => 'E-Mail-Adresse bestätigen'],
        'Der Link öffnet eine Seite, die nach dem Passwort fragt, das du bei der Registrierung gewählt hast. '
        . 'Mit dem richtigen Passwort ist deine Adresse bestätigt und du bist direkt angemeldet.',
        'Der Link gilt 24 Stunden und funktioniert genau einmal.',
        // Also read by people whose address somebody else typed in: say exactly what is stored and
        // for how long (the registration, until it is deleted). "Registrierung", never "Anmeldung":
        // "anmelden" is logging in.
        'Du hast dich gar nicht registriert? Dann ignoriere diese Mail einfach. Ohne Bestätigung wird '
        . 'das Konto nicht aktiviert, und die Registrierung wird nach 24 Stunden automatisch gelöscht.',
        'Bis gleich!' . "\n" . 'Dex von Smoke Detector',
    ],
];
