<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Auth\RequestPasswordReset;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/password-reset/request (operation requestPasswordReset): always 202 for a valid
 * request, whether or not the address has an account (FR-ACC-05).
 */
final readonly class RequestPasswordResetAction
{
    public function __construct(
        private RequestPasswordReset $requestPasswordReset,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $fields = new Fields(RequestBody::of($request), ['email']);
        $email = $fields->make('email', EmailAddress::fromInput(...));
        if ($email === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        $this->requestPasswordReset->execute($email, ClientAddress::of($request));

        return $this->responder->json(['status' => 'reset_requested'], 202);
    }
}
