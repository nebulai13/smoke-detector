<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoDuels;

/**
 * The `duels` table in the data export (schema ExportedDuel), the oldest first: role, dates, outcome
 * and the user's own side as it was scored. Of the opponent the id and nothing else (HLD §4.8
 * "Export and deletion"): the other side is the other user's data.
 */
final readonly class DuelsExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static function (Duel $duel) use ($userId): array {
                $isChallenger = $duel->isChallenger($userId);
                $decision = $duel->decision;
                $side = $decision?->sideOf($isChallenger);

                return [
                    'id' => $duel->id,
                    'status' => $duel->status->value,
                    'periodDays' => $duel->periodDays,
                    'role' => $isChallenger ? 'challenger' : 'opponent',
                    'opponentId' => $duel->otherThan($userId)?->value,
                    'createdAt' => ExportFormat::time($duel->createdAt),
                    'expiresAt' => ExportFormat::time($duel->expiresAt),
                    'answeredAt' => ExportFormat::nullableTime($duel->answeredAt),
                    'startsOn' => $duel->startOf($userId)?->value,
                    'decidedAt' => ExportFormat::nullableTime($decision?->decidedAt),
                    'outcome' => $decision?->winner->resultFor($isChallenger)->value,
                    // Sessions, always as floats (12.0, not 12), as baselineHistory.value.
                    'result' => $side === null ? null : [
                        'baseline' => self::sessions($side->b0Tenths),
                        'actual' => self::sessions($side->actualTenths),
                        'expected' => self::sessions($side->expectedTenths),
                        'smokeFreeDays' => $side->smokeFreeDays,
                        'checkIns' => $side->checkIns,
                        'xp' => $side->xp,
                    ],
                    'rulesVersion' => $decision?->rulesVersion,
                ];
            },
            (new PdoDuels($this->sql))->allOf($userId),
        );
    }

    private static function sessions(int $tenths): float
    {
        return $tenths / 10.0;
    }
}
