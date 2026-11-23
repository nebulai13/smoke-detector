<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Privacy\DeleteAccount;
use SmokeDetector\Application\Privacy\WrongPassword;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Input\PresentedPassword;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Http\RequestBody;

/**
 * DELETE /me (operation deleteMe): deletes the account of the access token's user, after the
 * password was entered again (FR-ACC-08). The password travels in the body, never in the URL.
 */
final readonly class DeleteMeAction
{
    public function __construct(
        private DeleteAccount $deleteAccount,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);

        $fields = new Fields(RequestBody::of($request), ['password']);
        $password = $fields->make('password', PresentedPassword::fromInput(...));
        if ($password === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        try {
            $this->deleteAccount->execute($userId, $password, ClientAddress::of($request));
        } catch (WrongPassword) {
            throw new ProblemException(Problem::wrongPassword());
        }

        return $this->responder->noContent();
    }
}
