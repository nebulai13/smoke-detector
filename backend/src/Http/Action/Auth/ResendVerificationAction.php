<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Auth\ResendVerification;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/resend-verification (operation resendVerification): always 202 for a valid request.
 */
final readonly class ResendVerificationAction
{
    public function __construct(
        private ResendVerification $resendVerification,
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

        $this->resendVerification->execute($email, ClientAddress::of($request));

        return $this->responder->json(['status' => 'verification_sent'], 202);
    }
}
