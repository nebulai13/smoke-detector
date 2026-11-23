<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\RequestBody;

/**
 * The bodies of the duel operations: {"duelId": "<uuid>"} (schema DuelReference) and
 * {"opponentId": "<uuid>", "periodDays": 7} (schema DuelProposal). Ids travel in the body, never in
 * the path: a path ends up in server logs (HLD §4.8).
 */
final readonly class DuelInput
{
    /**
     * @return string The duel id, lower-cased: any case is the same duel.
     *
     * @throws ValidationFailed When the body is not exactly one well-formed duel id.
     */
    public static function reference(ServerRequestInterface $request): string
    {
        $fields = new Fields(RequestBody::of($request), ['duelId']);
        $duelId = $fields->make('duelId', self::duelId(...));
        if ($duelId === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return $duelId;
    }

    /**
     * @return array{UserId, int} The friend who is challenged, and the days of the duel.
     *
     * @throws ValidationFailed With every error at once. A period that is not one of Duel::PERIODS
     *                          is "invalid_value".
     */
    public static function proposal(ServerRequestInterface $request): array
    {
        $fields = new Fields(RequestBody::of($request), ['opponentId', 'periodDays']);
        $opponentId = $fields->make('opponentId', UserReference::userId(...));
        $periodDays = $fields->integer('periodDays');
        if ($periodDays !== null && !in_array($periodDays, Duel::PERIODS, true)) {
            $fields->reject('periodDays', ErrorCode::InvalidValue, 'Must be one of: ' . implode(', ', Duel::PERIODS) . '.');
        }
        if ($opponentId === null || $periodDays === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return [$opponentId, $periodDays];
    }

    /**
     * @throws InvalidValue
     */
    private static function duelId(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be a UUID.');
        }

        return strtolower($value);
    }
}
