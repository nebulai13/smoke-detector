<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoLeagueResults;

/**
 * The `league_results` table in the data export (schema ExportedLeagueResult), the oldest first:
 * the user's own final results exactly as they were scored, and the rank once it is decided
 * (HLD §4.9 "Export and deletion"). When the cron job looks at a result next is left out.
 */
final readonly class LeagueResultsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (LeagueResult $result): array => [
                'type' => $result->period->type->value,
                'period' => $result->period->key,
                // Sessions, always as floats (12.0, not 12), as the result of a duel.
                'baseline' => self::sessions($result->b0Tenths),
                'actual' => self::sessions($result->actualTenths),
                'expected' => self::sessions($result->expectedTenths),
                'smokeFreeDays' => $result->smokeFreeDays,
                'checkIns' => $result->checkIns,
                'countedDays' => $result->countedDays,
                'rulesVersion' => $result->rulesVersion,
                'scoredAt' => ExportFormat::time($result->scoredAt),
                'rank' => $result->award?->rank,
                'participants' => $result->award?->participants,
                'xp' => $result->award?->xp,
                'rankedAt' => ExportFormat::nullableTime($result->award?->rankedAt),
            ],
            (new PdoLeagueResults($this->sql))->ofUser($userId),
        );
    }

    private static function sessions(int $tenths): float
    {
        return $tenths / 10.0;
    }
}
