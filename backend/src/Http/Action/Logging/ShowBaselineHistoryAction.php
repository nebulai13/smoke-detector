<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Logging;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\ShowBaselineHistory;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /me/baseline-history (operation getBaselineHistory, FR-PLAN-08). Each entry as the export
 * has it (schema ExportedBaseline).
 */
final readonly class ShowBaselineHistoryAction
{
    public function __construct(
        private ShowBaselineHistory $showBaselineHistory,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $entries = $this->showBaselineHistory->execute(AuthenticatedUser::id($request));

        return $this->responder->json(['entries' => array_map(
            static fn (BaselineEntry $entry): array => [
                'validFrom' => $entry->validFrom->value,
                // Always a float (12.0, not 12), so the document has one type for the member.
                'value' => $entry->value->toFloat(),
                'source' => $entry->source->value,
                'weekMean' => $entry->weekMean,
                'monthMean' => $entry->monthMean,
                'weekDays' => $entry->weekDays,
                'monthDays' => $entry->monthDays,
                'rulesVersion' => $entry->rulesVersion,
            ],
            $entries,
        )]);
    }
}
