<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\InvalidCredentials;
use SmokeDetector\Application\Auth\LogIn;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Http\Auth\SessionResponder;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Input\PresentedPassword;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/login (operation login).
 */
final readonly class LoginAction
{
    public function __construct(
        private LogIn $logIn,
        private SessionResponder $sessions,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $fields = new Fields(RequestBody::of($request), ['email', 'password', 'deviceLabel', 'refreshIn']);

        $email = $fields->make('email', EmailAddress::fromInput(...));
        $password = $fields->make('password', PresentedPassword::fromInput(...));
        $deviceLabel = $fields->makeOptional('deviceLabel', DeviceLabel::fromInput(...));
        $refreshIn = $fields->string('refreshIn');

        if ($refreshIn !== null && $refreshIn !== 'body' && $refreshIn !== 'cookie') {
            $fields->reject('refreshIn', ErrorCode::InvalidValue, 'Must be "body" or "cookie".');
        }

        if ($email === null || $password === null || $refreshIn === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        try {
            $session = $this->logIn->execute($email, $password, $deviceLabel, ClientAddress::of($request));
        } catch (InvalidCredentials) {
            throw new ProblemException(Problem::invalidCredentials());
        }

        return $refreshIn === 'cookie' ? $this->sessions->inCookie($session) : $this->sessions->inBody($session);
    }
}
