<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\Goal;

/**
 * PUT /me/plan (HLD §4.5 "Account", FR-ACC-06): the onboarding, and the changes a user may make
 * to the plan afterwards.
 *
 * The first call creates the plan and the first entry of the baseline history, valid from today
 * in the user's time zone. Later calls change goal, taper speed and default product (FR-PLAN-03).
 * They never touch the baseline: after the onboarding it changes through the weekly update only,
 * and its history is append-only (FR-PLAN-02). A later call may repeat the starting baseline of
 * the onboarding, so that an onboarding request that is sent twice is harmless, but no other value.
 */
final readonly class SavePlan
{
    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private PlanRepository $plans,
        private BaselineHistory $baselines,
        private RuleSets $rules,
    ) {
    }

    /**
     * @throws ValidationFailed When the starting baseline is missing, out of range or not the one of
     *                          the onboarding, or a reduce target is not below the current baseline.
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
                $this->change($plan, $choice, $stated);
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
        $today = LocalDate::of($now, $user->timeZone);

        $this->plans->save(new Plan($user->id, $choice->goal, $choice->taperSpeed, $choice->defaultProduct, $today, $rules->version));
        $this->baselines->append(BaselineEntry::onboarding($user->id, $today, $stated, $rules->version));
    }

    private function change(Plan $plan, PlanChoice $choice, ?Baseline $stated): void
    {
        if ($stated !== null && $this->baselines->starting($plan->userId)?->value->tenths !== $stated->tenths) {
            throw ValidationFailed::forField(
                'startingBaseline',
                ErrorCode::BaselineAlreadySet,
                'The baseline can be set only once. Leave it out, or send the value of the onboarding.',
            );
        }

        // Judged when the goal is set or changed (FR-ACC-06). A goal that stays is not judged again:
        // the baseline may have fallen below a target chosen earlier, and the user must still be
        // able to change the taper speed.
        if ($choice->goal->target !== $plan->goal->target) {
            $current = $this->baselines->latest($plan->userId);
            if ($current === null) {
                throw new LogicException('A plan exists without a baseline history.');
            }
            self::assertBelow($choice->goal, $current->value);
        }

        $this->plans->save($plan->changedTo($choice->goal, $choice->taperSpeed, $choice->defaultProduct));
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
