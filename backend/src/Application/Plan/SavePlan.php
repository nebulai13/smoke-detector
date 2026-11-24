<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Day\Calendars;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Day\Schedule;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\LocalDays;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Application\Time\ZoneHistory;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\Goal;

/**
 * PUT /me/plan (HLD §4.5 "Account", §4.6 "Plan and limit", FR-ACC-06): the onboarding, and the
 * changes a user may make to the plan afterwards.
 *
 * The first call creates the plan, the first entries of the histories of baseline, plan terms and
 * time zone, all valid from today in the user's time zone, and the user's progress. Later calls
 * change goal, taper speed and default product (FR-PLAN-03). Goal and taper speed count from the
 * user's next day, so that nobody raises today's limit after going over it; the product at once.
 * Later calls never touch the baseline: after the onboarding it changes through the weekly update
 * only, and its history is append-only (FR-PLAN-02). A later call may repeat the starting baseline
 * of the onboarding, so that an onboarding request that is sent twice is harmless, but no other value.
 *
 * Every call may name the day of the user's last session before joining (HLD §4.10), and replaces
 * the one named before: a call without it takes it away. It is a day before the first day of the
 * plan, because a session of a day of the plan is logged, not stated.
 */
final readonly class SavePlan
{
    /** The earliest day of a last session before joining that is believed. */
    private const string EARLIEST_LAST_SESSION = '1900-01-01';

    private Calendars $calendars;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private PlanRepository $plans,
        private BaselineHistory $baselines,
        private RuleSets $rules,
        private PlanHistory $planHistory,
        private ZoneHistory $zones,
        private ProgressRecords $progress,
    ) {
        $this->calendars = new Calendars($plans, $planHistory, $baselines, $zones, $rules);
    }

    /**
     * @throws ValidationFailed When the starting baseline is missing, out of range or not the one of
     *                          the onboarding, a reduce target is not below the current baseline, or
     *                          the day of the last session before joining is not before the plan.
     * @throws AccountNotFound  When the account was deleted in the meantime.
     */
    public function execute(UserId $userId, PlanChoice $choice): void
    {
        $stated = $choice->startingBaseline === null ? null : self::startingBaseline($choice->startingBaseline);
        $now = $this->clock->now();

        $this->transactions->run(function () use ($userId, $choice, $stated, $now): void {
            // The lock makes two requests of one user wait for each other: the second one finds
            // the plan of the first instead of writing a second onboarding.
            $user = $this->users->lock($userId) ?? throw new AccountNotFound();

            $plan = $this->plans->find($user->id);
            if ($plan === null) {
                $this->onboard($user, $choice, $stated, $now);
            } else {
                $this->change($plan, $choice, $stated, $now);
            }
        });
    }

    private function onboard(User $user, PlanChoice $choice, ?Baseline $stated, DateTimeImmutable $now): void
    {
        if ($stated === null) {
            throw ValidationFailed::forField('startingBaseline', ErrorCode::Required, 'Is required when the plan is set for the first time.');
        }
        self::assertBelow($choice->goal, $stated);

        $rules = $this->rules->current();
        // The user's day, not the server's: all day logic runs in the user's time zone (HLD §7).
        // Before the onboarding there is no zone history: the zone of the account simply counts.
        $today = LocalDate::of($now, $user->timeZone);
        self::assertBeforeJoining($choice->lastSessionBefore, $today);
        $zone = new ZoneChange($today, $user->timeZone);
        $days = new LocalDays([$zone]);

        $this->plans->save(new Plan($user->id, $choice->goal, $choice->taperSpeed, $choice->defaultProduct, $today, $rules->version, $choice->lastSessionBefore));
        $this->baselines->append(BaselineEntry::onboarding($user->id, $today, $stated, $rules->version));
        $this->planHistory->record(new PlanTerms($user->id, $today, $choice->goal, $choice->taperSpeed, $rules->version));
        $this->zones->record($user->id, $zone);
        // Nothing earned yet. The first day to become final is the first day of the plan.
        $this->progress->save(new ProgressRecord(
            $user->id,
            UserProgress::initial(),
            0,
            $today,
            Schedule::finalizeDueAt($days, $today),
            $days->startOf(Schedule::firstBaselineDay($today)),
        ));
    }

    private function change(Plan $plan, PlanChoice $choice, ?Baseline $stated, DateTimeImmutable $now): void
    {
        if ($stated !== null && $this->baselines->starting($plan->userId)?->value->tenths !== $stated->tenths) {
            throw ValidationFailed::forField(
                'startingBaseline',
                ErrorCode::BaselineAlreadySet,
                'The baseline can be set only once. Leave it out, or send the value of the onboarding.',
            );
        }
        self::assertBeforeJoining($choice->lastSessionBefore, $plan->startDate);

        $calendar = $this->calendars->ofPlan($plan);
        $today = $calendar->today($now);

        // Judged when the goal is set or changed (FR-ACC-06), against the baseline valid today. A
        // goal that stays is not judged again: the baseline may have fallen below a target chosen
        // earlier, and the user must still be able to change the taper speed.
        if ($choice->goal->target !== $plan->goal->target) {
            self::assertBelow($choice->goal, $calendar->baselineOn($today)->value);
        }

        // Only goal and taper speed are rules of the plan. A second change on the same day
        // replaces the entry for tomorrow, which has not counted yet.
        if ($choice->goal->target !== $plan->goal->target || $choice->taperSpeed !== $plan->taperSpeed) {
            $this->planHistory->record(new PlanTerms($plan->userId, $today->plusDays(1), $choice->goal, $choice->taperSpeed, $this->rules->current()->version));
        }

        $this->plans->save($plan->changedTo($choice->goal, $choice->taperSpeed, $choice->defaultProduct, $choice->lastSessionBefore));
    }

    private static function startingBaseline(int $sessionsPerDay): Baseline
    {
        try {
            return StartingBaseline::fromInput($sessionsPerDay);
        } catch (InvalidValue $rejected) {
            throw new ValidationFailed([$rejected->forField('startingBaseline')]);
        }
    }

    /**
     * HLD §4.10: the day of the last session before joining is before the first day of the plan, and
     * not before 1900.
     */
    private static function assertBeforeJoining(?LocalDate $lastSessionBefore, LocalDate $firstDay): void
    {
        if ($lastSessionBefore === null) {
            return;
        }
        if (!$lastSessionBefore->isBefore($firstDay) || $lastSessionBefore->isBefore(LocalDate::fromString(self::EARLIEST_LAST_SESSION))) {
            throw ValidationFailed::forField(
                'lastSessionBefore',
                ErrorCode::OutOfRange,
                'Must be a day before the first day of the plan, and not before 1900-01-01.',
            );
        }
    }

    /**
     * FR-ACC-06: a reduce target must be below the current baseline. Quitting (0) always is allowed.
     */
    private static function assertBelow(Goal $goal, Baseline $baseline): void
    {
        // Compared in tenths: a target of 7 is below 7.4 and not below 7.0.
        if (!$goal->isQuit() && $goal->target * 10 >= $baseline->tenths) {
            throw ValidationFailed::forField('target', ErrorCode::MustBeBelowBaseline, 'Must be below the current baseline.');
        }
    }
}
