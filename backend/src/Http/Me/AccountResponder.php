<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Me;

use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Application\Account\Account;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Http\JsonResponder;

/**
 * Writes an account as the schema "Me" of api/openapi.yaml: the answer of GET /me, PATCH /me and
 * PUT /me/plan.
 */
final readonly class AccountResponder
{
    public function __construct(private JsonResponder $responder)
    {
    }

    public function respond(Account $account): ResponseInterface
    {
        $user = $account->user;
        $me = [
            'id' => $user->id->value,
            'email' => $user->email->value,
            'displayName' => $user->displayName->value,
            'avatar' => $user->avatar->key,
            'locale' => $user->locale->value,
            'timeZone' => $user->timeZone->name,
            'onboardingComplete' => $account->onboardingComplete(),
        ];

        // Absent, not null, before the onboarding: that is how the contract says "no plan yet".
        if ($account->plan !== null) {
            $plan = $account->plan->plan;
            $me['plan'] = [
                'goalMode' => GoalMode::of($plan->goal)->value,
                'target' => $plan->goal->target,
                'taperSpeed' => $plan->taperSpeed->value,
                'defaultProduct' => $plan->defaultProduct->value,
                'startDate' => $plan->startDate->value,
                'baseline' => $account->plan->baseline->toFloat(),
                'dailyLimit' => $account->plan->dailyLimit,
            ];
            // Absent when the user named no day before joining.
            if ($plan->lastSessionBefore !== null) {
                $me['plan']['lastSessionBefore'] = $plan->lastSessionBefore->value;
            }
            // Absent while nothing waits for a later day.
            if ($account->plan->pendingFrom !== null) {
                $me['plan']['pendingFrom'] = $account->plan->pendingFrom->value;
            }
        }

        return $this->responder->json($me);
    }
}
