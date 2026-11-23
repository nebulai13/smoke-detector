<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Logging;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Day\ShowDays;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\DateParameter;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Logging\LoggingResponder;

/**
 * GET /days?from&to (operation listDays, FR-LOG-05).
 *
 * Checked here: that both are dates. Checked by the use case: that they make a range it may answer.
 */
final readonly class ListDaysAction
{
    public function __construct(
        private ShowDays $showDays,
        private LoggingResponder $logging,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $query = $request->getQueryParams();
        $from = DateParameter::read('from', $query['from'] ?? null);
        $to = DateParameter::read('to', $query['to'] ?? null);
        if ($from instanceof FieldError || $to instanceof FieldError) {
            throw new ValidationFailed(array_values(array_filter([$from, $to], static fn (mixed $value): bool => $value instanceof FieldError)));
        }

        return $this->responder->json(['days' => $this->logging->days($this->showDays->execute($userId, $from, $to))]);
    }
}
