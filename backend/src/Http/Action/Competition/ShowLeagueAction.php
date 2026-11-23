<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Competition;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Competition\ShowLeague;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Competition\LeaguesResponder;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /leagues?type&period (operation showLeague, FR-COMP-06, FR-COMP-08).
 *
 * Checked here: that both are there, and that the type is one. Checked by the use case: that the
 * period is "current" or a key of that type, which needs the type (and "current" the user's today).
 */
final readonly class ShowLeagueAction
{
    public function __construct(
        private ShowLeague $showLeague,
        private LeaguesResponder $leagues,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $query = $request->getQueryParams();
        $type = self::type($query['type'] ?? null);
        $period = self::period($query['period'] ?? null);
        if ($type instanceof FieldError || $period instanceof FieldError) {
            throw new ValidationFailed(array_values(array_filter([$type, $period], static fn (mixed $value): bool => $value instanceof FieldError)));
        }

        try {
            $league = $this->showLeague->show($userId, $type, $period);
        } catch (InvalidValue $refused) {
            throw new ValidationFailed([$refused->forField('period')]);
        }

        return $this->responder->json($this->leagues->league($league));
    }

    private static function type(mixed $value): LeaguePeriod|FieldError
    {
        if ($value === null) {
            return new FieldError('type', ErrorCode::Required, 'Is required.');
        }
        if (!is_string($value)) {
            return new FieldError('type', ErrorCode::WrongType, 'Must be a single value.');
        }

        return LeaguePeriod::tryFrom($value) ?? new FieldError('type', ErrorCode::InvalidValue, 'Must be week, month, quarter or half_year.');
    }

    private static function period(mixed $value): string|FieldError
    {
        if ($value === null) {
            return new FieldError('period', ErrorCode::Required, 'Is required.');
        }
        if (!is_string($value)) {
            return new FieldError('period', ErrorCode::WrongType, 'Must be a single value.');
        }

        return $value;
    }
}
