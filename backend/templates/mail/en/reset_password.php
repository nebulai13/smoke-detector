<?php

declare(strict_types=1);

// Password reset: the link to set a new password (SRS FR-ACC-05).
return [
    'subject' => 'New password for Smoke Detector',
    'blocks' => [
        'Hey!',
        'Forgot your password? Happens to the best of us. You can set a new one here:',
        ['link' => 'resetUrl', 'label' => 'Set a new password'],
        'The link is valid for 1 hour and works exactly once. Once you have set the new password, you '
        . 'are signed out on all devices and log in again with the new one.',
        'You did not ask for this? Then just ignore this mail. Your password stays as it is.',
        'Cheers' . "\n" . 'Dex from Smoke Detector',
    ],
];
