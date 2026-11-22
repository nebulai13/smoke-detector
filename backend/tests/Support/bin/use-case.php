<?php

declare(strict_types=1);

// A second process for the tests that need two sessions at the same moment (LockOrderTest): runs
// one use case of the real application against the test database and prints "ok" or the class of
// what was thrown.
//
//     php use-case.php <now, UTC> <verify-email|reset-password|refresh|logout|token-first> <token>
//     php use-case.php <now, UTC> <register|resend-verification|request-password-reset|login> <email>
//     php use-case.php <now, UTC> delete-account <user id>
//
// "token-first" is not a use case. It takes the locks in the order that is forbidden (the token,
// then the account), so that the test can show it would notice.

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\GivenConsent;
use SmokeDetector\Application\Auth\LogIn;
use SmokeDetector\Application\Auth\LogOut;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\RefreshSession;
use SmokeDetector\Application\Auth\RegisterUser;
use SmokeDetector\Application\Auth\Registration;
use SmokeDetector\Application\Auth\RequestPasswordReset;
use SmokeDetector\Application\Auth\ResendVerification;
use SmokeDetector\Application\Auth\ResetPassword;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Auth\VerifyEmail;
use SmokeDetector\Application\Privacy\DeleteAccount;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Config\Settings;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\TestAccounts;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

[, $now, $action, $token] = $argv + [null, '', '', ''];

$settings = Settings::fromEnvironment(getenv());
$container = (new ContainerFactory())->create($settings, [
    ClockInterface::class => FrozenClock::at((string) $now),
]);

$verifyEmail = $container->get(VerifyEmail::class);
$resetPassword = $container->get(ResetPassword::class);
$refreshSession = $container->get(RefreshSession::class);
$logOut = $container->get(LogOut::class);
$resendVerification = $container->get(ResendVerification::class);
$requestPasswordReset = $container->get(RequestPasswordReset::class);
$registerUser = $container->get(RegisterUser::class);
$logIn = $container->get(LogIn::class);
$deleteAccount = $container->get(DeleteAccount::class);
$transactions = $container->get(TransactionRunner::class);
$tokens = $container->get(OneTimeTokenRepository::class);
$users = $container->get(UserRepository::class);
\assert($verifyEmail instanceof VerifyEmail && $resetPassword instanceof ResetPassword && $refreshSession instanceof RefreshSession);
\assert($logOut instanceof LogOut && $transactions instanceof TransactionRunner);
\assert($logIn instanceof LogIn && $deleteAccount instanceof DeleteAccount);
\assert($tokens instanceof OneTimeTokenRepository && $users instanceof UserRepository);
\assert($resendVerification instanceof ResendVerification && $requestPasswordReset instanceof RequestPasswordReset && $registerUser instanceof RegisterUser);

try {
    match ($action) {
        'verify-email' => $verifyEmail->execute((string) $token, TestAccounts::PASSWORD, null, '203.0.113.99'),
        'reset-password' => $resetPassword->execute((string) $token, 'a brand new password'),
        'refresh' => $refreshSession->execute((string) $token),
        'logout' => $logOut->execute((string) $token),
        // For these three the fourth argument is the email address.
        'resend-verification' => $resendVerification->execute(EmailAddress::fromInput((string) $token), '203.0.113.99'),
        'request-password-reset' => $requestPasswordReset->execute(EmailAddress::fromInput((string) $token), '203.0.113.99'),
        'register' => $registerUser->execute(new Registration(
            EmailAddress::fromInput((string) $token),
            'a brand new password',
            DisplayName::fromInput('Anna'),
            Avatar::fromInput('avatar-01'),
            Locale::De,
            TimeZone::fromInput('Europe/Berlin'),
            new GivenConsent(true, $settings->consents->age18),
            new GivenConsent(true, $settings->consents->art9),
            new GivenConsent(true, $settings->consents->privacy),
        ), '203.0.113.99'),
        // With the password of the test accounts, as a client that was never seen before.
        'login' => $logIn->execute(EmailAddress::fromInput((string) $token), TestAccounts::PASSWORD, null, '203.0.113.99'),
        // The fourth argument is the id of the account.
        'delete-account' => $deleteAccount->execute(UserId::fromString((string) $token), TestAccounts::PASSWORD, '203.0.113.98'),
        'token-first' => $transactions->run(static function () use ($tokens, $users, $token): void {
            $stored = $tokens->lockByHash(TokenPurpose::VerifyEmail, hash('sha256', (string) $token));
            \assert($stored !== null);
            $users->lock($stored->userId);
        }),
        default => throw new InvalidArgumentException("Unknown action \"{$action}\"."),
    };
    echo 'ok';
} catch (Throwable $failure) {
    echo $failure::class;
}
