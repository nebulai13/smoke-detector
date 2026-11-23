<?php

declare(strict_types=1);

// Registration, or a request for a new confirmation link, with the address of an existing account:
// says that the account exists, points to login and password reset, and changes nothing (SRS
// FR-ACC-01). The text must fit both requests.
return [
    'subject' => 'You already have a Smoke Detector account',
    'blocks' => [
        'Hey!',
        // "A short while ago", not "just": the mail is sent a minute or two after the request, and
        // later when the mail server had to be tried again.
        'There already is a Smoke Detector account for this email address: yours. A sign-up or a new '
        . 'confirmation link was requested with it a short while ago, and neither is needed any more.',
        'If that was you, simply log in:',
        ['link' => 'loginUrl', 'label' => 'Log in'],
        'Forgot your password? No problem, you can set a new one here:',
        ['link' => 'resetUrl', 'label' => 'Reset password'],
        'That was not you? Then there is nothing you need to do. Nothing about your account was '
        . 'changed, and nobody got access to it.',
        'Cheers' . "\n" . 'Dex from Smoke Detector',
    ],
];
