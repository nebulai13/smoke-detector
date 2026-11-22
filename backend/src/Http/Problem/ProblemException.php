<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Problem;

use RuntimeException;
use Throwable;

/**
 * Ends the request with the given problem as the response.
 *
 * Thrown by the HTTP layer only (actions and middleware). Use cases throw their own exceptions, and
 * the action translates them: the application layer does not know about HTTP.
 */
final class ProblemException extends RuntimeException
{
    public function __construct(
        public readonly Problem $problem,
        ?Throwable $previous = null,
    ) {
        parent::__construct($problem->title, 0, $previous);
    }
}
