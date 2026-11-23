<?php

declare(strict_types=1);

// Password reset: the link to set a new password (SRS FR-ACC-05).
return [
    'subject' => 'Neues Passwort für Smoke Detector',
    'blocks' => [
        'Hey!',
        'Passwort vergessen? Passiert den Besten. Hier kannst du ein neues setzen:',
        ['link' => 'resetUrl', 'label' => 'Neues Passwort setzen'],
        'Der Link gilt 1 Stunde und funktioniert genau einmal. Sobald du ein neues Passwort gesetzt hast, '
        . 'wirst du auf allen Geräten abgemeldet und meldest dich mit dem neuen Passwort wieder an.',
        'Du hast das gar nicht angefordert? Dann ignoriere diese Mail einfach. Dein Passwort bleibt, '
        . 'wie es ist.',
        'Viele Grüße' . "\n" . 'Dex von Smoke Detector',
    ],
];
