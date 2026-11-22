<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Input\TokenField;
use SmokeDetector\Http\RequestBody;

/**
 * The refresh token of a refresh or logout request, and the way it came: in the body (native apps)
 * or in the cookie (web app). The answer goes back the same way.
 */
final readonly class PresentedRefreshToken
{
    private function __construct(
        /** Null when the request carried no usable token at all. */
        #[SensitiveParameter]
        public ?string $token,
        /** True when the request had a "refreshToken" in its body: body mode, no cookie is touched. */
        public bool $inBody,
        /**
         * True when the browser is to be told to drop the cookie should this token turn out
         * worthless: the request is not in body mode and did bring the cookie (see RefreshCookie).
         */
        public bool $cookieToClear,
    ) {
    }

    /**
     * @throws ValidationFailed When the body is not as the contract says, or when a token comes in
     *                          the body and in the cookie at once: nothing says which one is meant,
     *                          and guessing is how the wrong session gets ended or kept.
     */
    public static function of(ServerRequestInterface $request): self
    {
        $fields = new Fields(RequestBody::of($request), ['refreshToken']);
        $fromBody = $fields->makeOptional('refreshToken', TokenField::fromInput(...));
        $fromCookie = RefreshCookie::read($request);

        if ($fromBody !== null && $fromCookie !== null) {
            $fields->reject('refreshToken', ErrorCode::Ambiguous, 'Send the refresh token in the body or in the cookie, not in both.');
        }
        if ($fields->hasErrors()) {
            throw $fields->failure();
        }

        return new self($fromBody ?? $fromCookie, $fromBody !== null, $fromBody === null && RefreshCookie::isPresented($request));
    }
}
