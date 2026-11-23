<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Me;

use BackedEnum;
use DateTimeImmutable;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Application\Plan\PlanChoice;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Plan\StartingBaseline;
use SmokeDetector\Application\Privacy\ExportAccount;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use Symfony\Component\Yaml\Yaml;

/**
 * PUT /me/plan and the plan in GET /me, through the real application and database
 * (HLD §4.5 "Account", FR-ACC-06, FR-PLAN-01 to 03).
 */
final class PlanTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    public function test_FR_ACC_06_the_onboarding_gives_the_account_its_plan(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($anna));

        self::assertSame(200, $response->status());
        $expected = [
            'id' => $anna->id->value,
            'email' => 'anna@example.org',
            'displayName' => 'Test User',
            'avatar' => 'avatar-01',
            'locale' => 'de',
            'timeZone' => 'Europe/Berlin',
            'onboardingComplete' => true,
            'plan' => [
                'goalMode' => 'reduce',
                'target' => 5,
                'taperSpeed' => 'normal',
                'defaultProduct' => 'rolled',
                'startDate' => '2026-11-17',
                'baseline' => 12.0,
                // 80 % of 12 is 9.6.
                'dailyLimit' => 9,
            ],
        ];
        self::assertSame($expected, $response->json());
        self::assertSame($expected, $this->call('GET', '/me', headers: $this->authorization($anna))->json());
    }

    public function test_FR_PLAN_02_the_onboarding_stores_the_plan_and_the_first_baseline_with_the_rules_version(): void
    {
        $anna = $this->givenUser();

        $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($anna));

        self::assertSame(
            [['user_id' => $anna->id->value, 'goal_mode' => 'reduce', 'target' => 5, 'taper_speed' => 'normal', 'default_product' => 'rolled', 'start_date' => '2026-11-17', 'rules_version' => 'v1']],
            $this->sql()->rows('SELECT * FROM plans'),
        );
        self::assertSame(
            [['user_id' => $anna->id->value, 'valid_from' => '2026-11-17', 'value' => '12.0', 'source' => 'onboarding', 'w_mean' => null, 'm_mean' => null, 'w_days' => null, 'm_days' => null, 'rules_version' => 'v1']],
            $this->sql()->rows('SELECT * FROM baseline_history'),
        );
    }

    public function test_FR_PLAN_02_the_baseline_is_valid_from_the_users_local_day(): void
    {
        // 23:30 UTC on the 17th: already the 18th in Berlin, still the 17th in New York.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-17 23:30:00 UTC'));
        $berlin = $this->givenUser('berlin@example.org');
        $newYork = $this->givenUser('newyork@example.org');
        $this->call('PATCH', '/me', ['timeZone' => 'America/New_York'], $this->authorization($newYork));

        $inBerlin = $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($berlin))->json();
        $inNewYork = $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($newYork))->json();

        self::assertSame('2026-11-18', self::plan($inBerlin)['startDate'] ?? null);
        self::assertSame('2026-11-17', self::plan($inNewYork)['startDate'] ?? null);
        self::assertSame(
            [['email' => 'berlin@example.org', 'valid_from' => '2026-11-18'], ['email' => 'newyork@example.org', 'valid_from' => '2026-11-17']],
            $this->sql()->rows('SELECT u.email, b.valid_from FROM baseline_history b JOIN users u ON u.id = b.user_id ORDER BY u.email'),
        );
    }

    public function test_FR_PLAN_01_quitting_and_the_three_taper_speeds_give_their_limits(): void
    {
        $limits = [];
        foreach (['gentle', 'normal', 'fast'] as $speed) {
            $user = $this->givenUser("{$speed}@example.org");
            $response = $this->call('PUT', '/me/plan', ['startingBaseline' => 20, 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => $speed, 'defaultProduct' => 'vape'], $this->authorization($user));
            self::assertSame(200, $response->status(), $response->body());
            $limits[$speed] = [self::plan($response->json())['dailyLimit'] ?? null, self::plan($response->json())['goalMode'] ?? null, self::plan($response->json())['target'] ?? null];
        }

        self::assertSame(['gentle' => [18, 'quit', 0], 'normal' => [16, 'quit', 0], 'fast' => [14, 'quit', 0]], $limits);
    }

    public function test_FR_PLAN_01_the_limit_follows_the_weekly_baseline_but_never_goes_below_the_target(): void
    {
        $anna = $this->onboarded();
        $this->givenWeeklyBaseline($anna, '2026-11-23', 74);

        $plan = self::plan($this->call('GET', '/me', headers: $this->authorization($anna))->json());
        // 80 % of 7.4 is 5.92.
        self::assertSame([7.4, 5], [$plan['baseline'] ?? null, $plan['dailyLimit'] ?? null]);

        $this->givenWeeklyBaseline($anna, '2026-11-30', 51);
        $plan = self::plan($this->call('GET', '/me', headers: $this->authorization($anna))->json());
        // 80 % of 5.1 is 4.08, but the target is 5.
        self::assertSame([5.1, 5], [$plan['baseline'] ?? null, $plan['dailyLimit'] ?? null]);
    }

    public function test_FR_ACC_06_goal_target_taper_speed_and_product_can_be_changed_later(): void
    {
        $anna = $this->onboarded();
        $history = $this->sql()->rows('SELECT * FROM baseline_history');
        $this->clock()->advanceSeconds(5 * 86_400);

        $response = $this->call('PUT', '/me/plan', ['goalMode' => 'reduce', 'target' => 3, 'taperSpeed' => 'fast', 'defaultProduct' => 'heated_tobacco'], $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame(
            ['goalMode' => 'reduce', 'target' => 3, 'taperSpeed' => 'fast', 'defaultProduct' => 'heated_tobacco', 'startDate' => '2026-11-17', 'baseline' => 12.0, 'dailyLimit' => 8],
            self::plan($response->json()),
        );
        // The baseline history is as it was, and there is still one plan.
        self::assertSame($history, $this->sql()->rows('SELECT * FROM baseline_history'));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM plans'));
    }

    public function test_FR_PLAN_03_switching_from_reduce_to_quit_resets_nothing(): void
    {
        $anna = $this->onboarded();
        $this->givenWeeklyBaseline($anna, '2026-11-23', 74);
        $history = $this->sql()->rows('SELECT * FROM baseline_history ORDER BY valid_from');

        $response = $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'], $this->authorization($anna));

        self::assertSame(
            ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled', 'startDate' => '2026-11-17', 'baseline' => 7.4, 'dailyLimit' => 5],
            self::plan($response->json()),
        );
        self::assertSame($history, $this->sql()->rows('SELECT * FROM baseline_history ORDER BY valid_from'));
    }

    public function test_FR_ACC_06_the_baseline_cannot_be_changed_by_a_later_call(): void
    {
        $anna = $this->onboarded();
        $plans = $this->sql()->rows('SELECT * FROM plans');
        $history = $this->sql()->rows('SELECT * FROM baseline_history');

        $response = $this->call('PUT', '/me/plan', ['startingBaseline' => 20, 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'vape'], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            [['field' => 'startingBaseline', 'code' => 'baseline_already_set']],
            self::fieldCodes($problem),
        );
        // Nothing of the rejected request was stored, the other members included.
        self::assertSame($plans, $this->sql()->rows('SELECT * FROM plans'));
        self::assertSame($history, $this->sql()->rows('SELECT * FROM baseline_history'));
    }

    public function test_FR_ACC_06_an_onboarding_request_that_is_sent_twice_is_harmless(): void
    {
        $anna = $this->onboarded();
        $this->clock()->advanceSeconds(2 * 86_400);

        $response = $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($anna));

        self::assertSame(200, $response->status());
        self::assertSame('2026-11-17', self::plan($response->json())['startDate'] ?? null);
        self::assertSame(['2026-11-17'], $this->sql()->column('SELECT valid_from FROM baseline_history'));
    }

    public function test_FR_ACC_06_a_reduce_target_must_be_below_the_baseline(): void
    {
        $anna = $this->givenUser();

        foreach ([12, 30] as $target) {
            $response = $this->call('PUT', '/me/plan', ['target' => $target] + self::PLAN, $this->authorization($anna));

            $problem = self::assertProblem(422, 'validation_failed', $response);
            self::assertSame([['field' => 'target', 'code' => 'must_be_below_baseline']], self::fieldCodes($problem));
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM plans'));
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM baseline_history'));

        self::assertSame(200, $this->call('PUT', '/me/plan', ['target' => 11] + self::PLAN, $this->authorization($anna))->status());
    }

    public function test_FR_ACC_06_a_later_target_is_judged_against_the_current_baseline(): void
    {
        $anna = $this->onboarded();
        $this->givenWeeklyBaseline($anna, '2026-11-23', 74);

        $tooHigh = $this->call('PUT', '/me/plan', ['goalMode' => 'reduce', 'target' => 8, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'], $this->authorization($anna));
        $below = $this->call('PUT', '/me/plan', ['goalMode' => 'reduce', 'target' => 7, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'], $this->authorization($anna));

        self::assertProblem(422, 'validation_failed', $tooHigh);
        self::assertSame(200, $below->status());
        self::assertSame(7, $this->sql()->value('SELECT target FROM plans'));
    }

    public function test_FR_ACC_06_the_starting_baseline_is_a_whole_number_from_1_to_80(): void
    {
        $anna = $this->givenUser();

        foreach ([0, 81, -1, 12.5, '12', null] as $baseline) {
            $response = $this->callInvalid('PUT', '/me/plan', ['startingBaseline' => $baseline, 'goalMode' => 'quit', 'target' => 0] + self::PLAN, $this->authorization($anna));

            $problem = self::assertProblem(422, 'validation_failed', $response);
            self::assertSame(
                [['field' => 'startingBaseline', 'code' => is_int($baseline) ? 'out_of_range' : 'wrong_type']],
                self::fieldCodes($problem),
            );
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM plans'));

        foreach ([1, 80] as $number => $baseline) {
            $user = $this->givenUser("edge{$number}@example.org");
            $response = $this->call('PUT', '/me/plan', ['startingBaseline' => $baseline, 'goalMode' => 'quit', 'target' => 0] + self::PLAN, $this->authorization($user));
            self::assertSame((float) $baseline, self::plan($response->json())['baseline'] ?? null);
        }
    }

    public function test_FR_ACC_06_the_first_plan_needs_the_starting_baseline(): void
    {
        $anna = $this->givenUser();

        $response = $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame([['field' => 'startingBaseline', 'code' => 'required']], self::fieldCodes($problem));
    }

    public function test_FR_ACC_06_goal_mode_and_target_must_fit_each_other(): void
    {
        $anna = $this->givenUser();

        $quitWithTarget = $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 5] + self::PLAN, $this->authorization($anna));
        $reduceToNothing = $this->call('PUT', '/me/plan', ['goalMode' => 'reduce', 'target' => 0] + self::PLAN, $this->authorization($anna));

        self::assertSame(
            [['field' => 'target', 'code' => 'goal_target_mismatch']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $quitWithTarget)),
        );
        self::assertSame(
            [['field' => 'target', 'code' => 'goal_target_mismatch']],
            self::fieldCodes(self::assertProblem(422, 'validation_failed', $reduceToNothing)),
        );
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM plans'));
    }

    public function test_FR_ACC_06_every_invalid_member_of_the_plan_is_reported(): void
    {
        $anna = $this->givenUser();

        $response = $this->callInvalid('PUT', '/me/plan', [
            'startingBaseline' => 100, 'goalMode' => 'maintain', 'target' => -1, 'taperSpeed' => 'slow', 'defaultProduct' => 'snus', 'baseline' => 12,
        ], $this->authorization($anna));

        $problem = self::assertProblem(422, 'validation_failed', $response);
        self::assertSame(
            [
                ['field' => 'baseline', 'code' => 'unknown_field'],
                ['field' => 'startingBaseline', 'code' => 'out_of_range'],
                ['field' => 'goalMode', 'code' => 'invalid_value'],
                ['field' => 'target', 'code' => 'out_of_range'],
                ['field' => 'taperSpeed', 'code' => 'invalid_value'],
                ['field' => 'defaultProduct', 'code' => 'invalid_value'],
            ],
            self::fieldCodes($problem),
        );
    }

    public function test_FR_ACC_06_missing_members_and_an_empty_body_are_rejected(): void
    {
        $anna = $this->givenUser();

        $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('PUT', '/me/plan', null, $this->authorization($anna)));

        self::assertSame(
            [
                ['field' => 'goalMode', 'code' => 'required'],
                ['field' => 'target', 'code' => 'required'],
                ['field' => 'taperSpeed', 'code' => 'required'],
                ['field' => 'defaultProduct', 'code' => 'required'],
            ],
            self::fieldCodes($problem),
        );
    }

    public function test_FR_ACC_06_a_target_beyond_what_can_be_stored_is_rejected(): void
    {
        $anna = $this->givenUser();

        foreach ([1000, 70000] as $target) {
            $response = $this->callInvalid('PUT', '/me/plan', ['target' => $target] + self::PLAN, $this->authorization($anna));

            self::assertSame(
                [['field' => 'target', 'code' => 'out_of_range']],
                self::fieldCodes(self::assertProblem(422, 'validation_failed', $response)),
            );
        }
    }

    public function test_FR_ACC_06_every_product_type_can_be_chosen(): void
    {
        $anna = $this->givenUser();
        $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($anna));

        foreach (ProductType::cases() as $type) {
            $response = $this->call('PUT', '/me/plan', ['goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'normal', 'defaultProduct' => $type->value], $this->authorization($anna));

            self::assertSame($type->value, self::plan($response->json())['defaultProduct'] ?? null);
        }
    }

    public function test_FR_ACC_06_a_plan_belongs_to_the_user_of_the_token_alone(): void
    {
        $anna = $this->onboarded('anna@example.org');
        $bob = $this->givenUser('bob@example.org');

        self::assertFalse($this->call('GET', '/me', headers: $this->authorization($bob))->json()['onboardingComplete'] ?? null);
        self::assertArrayNotHasKey('plan', $this->call('GET', '/me', headers: $this->authorization($bob))->json());

        $this->call('PUT', '/me/plan', ['startingBaseline' => 40, 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'defaultProduct' => 'cigar'], $this->authorization($bob));

        self::assertSame(12.0, self::plan($this->call('GET', '/me', headers: $this->authorization($anna))->json())['baseline'] ?? null);
        self::assertSame(40.0, self::plan($this->call('GET', '/me', headers: $this->authorization($bob))->json())['baseline'] ?? null);
        self::assertProblem(401, 'unauthorized', $this->callInvalid('PUT', '/me/plan', self::PLAN));
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);
        $values = static fn (BackedEnum ...$cases): array => array_map(static fn (BackedEnum $case): int|string => $case->value, array_values($cases));

        self::assertSame($values(...ProductType::cases()), $schema('ProductType', 'enum'));
        self::assertSame($values(...TaperSpeed::cases()), $schema('TaperSpeed', 'enum'));
        self::assertSame($values(...GoalMode::cases()), $schema('GoalMode', 'enum'));
        self::assertSame($values(...BaselineSource::cases()), $schema('ExportedBaseline', 'properties', 'source', 'enum'));
        self::assertSame($values(...ConsentType::cases()), $schema('ExportedConsent', 'properties', 'type', 'enum'));
        self::assertSame($values(...TokenPurpose::cases()), $schema('ExportedMailLink', 'properties', 'purpose', 'enum'));
        self::assertSame(StartingBaseline::MIN, $schema('PlanRequest', 'properties', 'startingBaseline', 'minimum'));
        self::assertSame(StartingBaseline::MAX, $schema('PlanRequest', 'properties', 'startingBaseline', 'maximum'));
        self::assertSame([0, PlanChoice::MAX_TARGET], [$schema('PlanTarget', 'minimum'), $schema('PlanTarget', 'maximum')]);
        self::assertSame(Avatar::PRESETS, $schema('AvatarKey', 'enum'));
        self::assertSame(PasswordPolicy::MAX_LENGTH, $schema('DeleteMeRequest', 'properties', 'password', 'maxLength'));
        self::assertSame([ExportAccount::FORMAT_VERSION], $schema('AccountExport', 'properties', 'formatVersion', 'enum'));
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function at(array $node, string ...$keys): mixed
    {
        $value = $node;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A user who has done the onboarding with self::PLAN, on 2026-11-17.
     */
    private function onboarded(string $email = 'anna@example.org'): User
    {
        $user = $this->givenUser($email);
        self::assertSame(200, $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($user))->status());

        return $user;
    }

    /**
     * Stores what the weekly update will store (build step 4).
     */
    private function givenWeeklyBaseline(User $user, string $validFrom, int $tenths): void
    {
        $this->service(BaselineHistory::class)->append(new BaselineEntry(
            $user->id, LocalDate::fromString($validFrom), Baseline::fromTenths($tenths), BaselineSource::Weekly, 7.0, 8.0, 7, 28, 'v1',
        ));
    }

    /**
     * @param array<string, mixed> $me
     *
     * @return array<array-key, mixed>
     */
    private static function plan(array $me): array
    {
        self::assertIsArray($me['plan'] ?? null, 'The account has no plan: ' . json_encode($me));

        return $me['plan'];
    }
}
