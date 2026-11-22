<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Logging;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Day\CheckIn;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\DateParameter;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Logging\LoggingResponder;

/**
 * POST /days/{date}/check-in (operation checkInDay, FR-LOG-04). The request has no body.
 */
final readonly class CheckInAction
{
    public function __construct(
        private CheckIn $checkIn,
        private LoggingResponder $logging,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        // The router hands the path segment over as a request attribute.
        $date = DateParameter::read('date', $request->getAttribute('date'));
        if ($date instanceof FieldError) {
            throw new ValidationFailed([$date]);
        }

        $result = $this->checkIn->execute($userId, $date);

        return $this->responder->json([
            'day' => $this->logging->day($result->day),
            'events' => $this->logging->events($result->events),
            'progress' => $this->logging->progress($result->progress),
        ]);
    }
}
