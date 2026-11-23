<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Domain\Competition\CompetitionScore;
use SmokeDetector\Domain\Competition\Reduction;

/**
 * The final result of a user for one league period (HLD §4.3 "league_results", §4.9 "Final
 * scores"): stored when the last day of the period becomes final, and never changed afterwards
 * except for the rank (NFR-04).
 */
final readonly class LeagueResult
{
    public function __construct(
        public UserId $userId,
        public CalendarPeriod $period,
        /** The frozen baseline B0, tenths of a session per day. */
        public int $b0Tenths,
        public int $actualTenths,
        public int $expectedTenths,
        public int $smokeFreeDays,
        public int $checkIns,
        /** The days of the period the user was rated over: from the first day of the plan if that is later. */
        public int $countedDays,
        public string $rulesVersion,
        public DateTimeImmutable $scoredAt,
        /** When the cron step looks at the result next, to rank it. */
        public DateTimeImmutable $checkAt,
        /** Null until the rank is decided. */
        public ?LeagueAward $award = null,
    ) {
    }

    /**
     * The result as it is compared with others.
     */
    public function score(): CompetitionScore
    {
        return new CompetitionScore(
            Reduction::fromTotals($this->actualTenths, $this->expectedTenths),
            $this->smokeFreeDays,
            $this->checkIns,
            $this->countedDays,
        );
    }

    public function checkedAgainAt(DateTimeImmutable $checkAt): self
    {
        return new self(
            $this->userId, $this->period, $this->b0Tenths, $this->actualTenths, $this->expectedTenths, $this->smokeFreeDays,
            $this->checkIns, $this->countedDays, $this->rulesVersion, $this->scoredAt, $checkAt, $this->award,
        );
    }

    public function ranked(LeagueAward $award): self
    {
        return new self(
            $this->userId, $this->period, $this->b0Tenths, $this->actualTenths, $this->expectedTenths, $this->smokeFreeDays,
            $this->checkIns, $this->countedDays, $this->rulesVersion, $this->scoredAt, $this->checkAt, $award,
        );
    }
}
