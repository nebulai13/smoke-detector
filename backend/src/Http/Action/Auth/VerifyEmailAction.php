<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\VerifyEmail;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Http\Auth\SessionResponder;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Input\PresentedPassword;
use SmokeDetector\Http\Input\TokenField;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/verify-email (operation verifyEmail): the token of the confirmation link plus the
 * password of the sign-up confirm the account and log in, answered exactly like a login
 * (FR-ACC-03).
 */
final readonly class VerifyEmailAction
{
    public function __construct(
        private VerifyEmail $verifyEmail,
        private SessionResponder $sessions,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $fields = new Fields(RequestBody::of($request), ['token', 'password', 'deviceLabel', 'refreshIn']);

        $token = $fields->make('token', TokenField::fromInput(...));
        $password = $fields->make('password', PresentedPassword::fromInput(...));
        $deviceLabel = $fields->makeOptional('deviceLabel', DeviceLabel::fromInput(...));
        $refreshIn = $fields->string('refreshIn');

        if ($refreshIn !== null && $refreshIn !== 'body' && $refreshIn !== 'cookie') {
            $fields->reject('refreshIn', ErrorCode::InvalidValue, 'Must be "body" or "cookie".');
        }

        if ($token === null || $password === null || $refreshIn === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        try {
            $session = $this->verifyEmail->execute($token, $password, $deviceLabel, ClientAddress::of($request));
        } catch (InvalidToken) {
            throw new ProblemException(Problem::invalidToken());
        } catch (InvalidCredentials) {
            // Nothing of the registration goes into the answer: not its address, not its name.
            throw new ProblemException(Problem::wrongSignUpPassword());
        }

        return $refreshIn === 'cookie' ? $this->sessions->inCookie($session) : $this->sessions->inBody($session);
    }
}
