<?php

declare(strict_types=1);

// Registration: the link that confirms the email address (SRS FR-ACC-01, FR-ACC-03).
return [
    'subject' => 'Almost there: confirm your email address',
    'blocks' => [
        'Hey!',
        'Great to have you at Smoke Detector. Dex has already charged the batteries. '
        . 'Just confirm your email address and you are good to go:',
        ['link' => 'confirmUrl', 'label' => 'Confirm email address'],
        'The link opens a page that asks for the password you chose when you signed up. '
        . 'With the right password your address is confirmed and you are logged in right away.',
        'The link is valid for 24 hours and works exactly once.',
        // Also read by people whose address somebody else typed in: say exactly what is stored and
        // for how long (the registration, until it is deleted).
        'You did not sign up? Then just ignore this mail. Without the confirmation the account is '
        . 'never activated, and the registration is deleted automatically after 24 hours.',
        'See you in a moment!' . "\n" . 'Dex from Smoke Detector',
    ],
];
