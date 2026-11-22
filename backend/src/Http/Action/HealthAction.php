<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action;

use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Application\Health\CheckHealth;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;

/**
 * GET /health (operation getHealth): 200 with the release, or 503 when the database is down.
 *
 * An action only translates: request → use case → response. The members of the response are named
 * exactly as in api/openapi.yaml.
 */
final readonly class HealthAction
{
    public function __construct(
        private CheckHealth $checkHealth,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(): ResponseInterface
    {
        $report = $this->checkHealth->execute();
        if (!$report->isHealthy()) {
            // Says which part is down, and nothing about where it is or why.
            throw new ProblemException(Problem::serviceUnavailable('The database cannot be reached.'));
        }

        return $this->responder->json([
            'status' => 'ok',
            'version' => $report->version,
            'cronLastRunAt' => $report->cronLastRunAt?->format(DateTimeInterface::RFC3339),
        ]);
    }
}
