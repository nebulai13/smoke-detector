<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Auth\ResetPassword;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Input\TokenField;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/password-reset/confirm (operation confirmPasswordReset): sets the new password and
 * ends every session of the user (FR-ACC-05). It does not log in.
 */
final readonly class ConfirmPasswordResetAction
{
    public function __construct(
        private ResetPassword $resetPassword,
        private PasswordPolicy $passwordPolicy,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $fields = new Fields(RequestBody::of($request), ['token', 'password']);
        $token = $fields->make('token', TokenField::fromInput(...));
        $password = $fields->string('password');
        if ($password !== null) {
            $fields->rejectIf('password', $this->passwordPolicy->violation($password));
        }
        if ($token === null || $password === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        try {
            $this->resetPassword->execute($token, $password);
        } catch (InvalidToken) {
            throw new ProblemException(Problem::invalidToken());
        }

        return $this->responder->json(['status' => 'password_reset']);
    }
}
