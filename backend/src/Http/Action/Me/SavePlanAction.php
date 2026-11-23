<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\ShowAccount;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Application\Plan\PlanChoice;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Plan\SavePlan;
use SmokeDetector\Application\Plan\StartingBaseline;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Me\AccountResponder;
use SmokeDetector\Http\RequestBody;

/**
 * PUT /me/plan (operation saveMyPlan): the onboarding and later changes of the plan (FR-ACC-06).
 *
 * Checked here: what each member must look like. Checked by the use case: what needs stored data
 * (whether the baseline may be set, whether the target is below the current baseline).
 */
final readonly class SavePlanAction
{
    public function __construct(
        private SavePlan $savePlan,
        private ShowAccount $showAccount,
        private AccountResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);

        $fields = new Fields(RequestBody::of($request), ['startingBaseline', 'goalMode', 'target', 'taperSpeed', 'defaultProduct']);

        $startingBaseline = $fields->optionalInteger('startingBaseline');
        if ($startingBaseline !== null) {
            // The use case checks the range as well. Checked here too, so that one answer lists
            // everything that is wrong with the form.
            try {
                StartingBaseline::fromInput($startingBaseline);
            } catch (InvalidValue $rejected) {
                $fields->rejectIf('startingBaseline', $rejected);
            }
        }

        $mode = $fields->enum('goalMode', GoalMode::class);
        $target = $fields->integer('target');
        if ($target !== null && ($target < 0 || $target > PlanChoice::MAX_TARGET)) {
            $fields->reject('target', ErrorCode::OutOfRange, 'Must be a whole number from 0 to ' . PlanChoice::MAX_TARGET . '.');
            $target = null;
        }
        $goal = $mode === null || $target === null ? null : self::goal($mode, $target, $fields);

        $taperSpeed = $fields->enum('taperSpeed', TaperSpeed::class);
        $defaultProduct = $fields->enum('defaultProduct', ProductType::class);

        if ($goal === null || $taperSpeed === null || $defaultProduct === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        $this->savePlan->execute($userId, new PlanChoice($startingBaseline, $goal, $taperSpeed, $defaultProduct));

        return $this->responder->respond($this->showAccount->execute($userId));
    }

    /**
     * Mode and target say the same thing twice (quit is target 0), so they must agree.
     */
    private static function goal(GoalMode $mode, int $target, Fields $fields): ?Goal
    {
        if ($mode === GoalMode::Quit) {
            if ($target !== 0) {
                $fields->reject('target', ErrorCode::GoalTargetMismatch, 'Must be 0 when the goal is to quit.');

                return null;
            }

            return Goal::quit();
        }

        try {
            return Goal::reduceTo($target);
        } catch (InvalidArgumentException) {
            // The domain's own message names a PHP method, which is nothing a client knows.
            $fields->reject(
                'target',
                ErrorCode::GoalTargetMismatch,
                'Must be at least 1 when the goal is to reduce. To stop altogether, the goal is "quit".',
            );

            return null;
        }
    }
}
