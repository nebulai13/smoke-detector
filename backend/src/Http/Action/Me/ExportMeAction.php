<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Privacy\ExportAccount;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /me/export (operation exportMe): everything stored about the user as one JSON document
 * (FR-ACC-07). Like every response it is sent with "Cache-Control: no-store".
 */
final readonly class ExportMeAction
{
    public function __construct(
        private ExportAccount $exportAccount,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->exportAccount->execute(AuthenticatedUser::id($request)));
    }
}
