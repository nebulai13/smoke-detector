<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\ShowAccount;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Me\AccountResponder;

/**
 * GET /me (operation getMe).
 */
final readonly class ShowMeAction
{
    public function __construct(
        private ShowAccount $showAccount,
        private AccountResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->showAccount->execute(AuthenticatedUser::id($request)));
    }
}
