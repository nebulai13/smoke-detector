<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Me;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\ProfileChange;
use SmokeDetector\Application\Account\ShowAccount;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\UpdateProfile;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\Me\AccountResponder;
use SmokeDetector\Http\RequestBody;

/**
 * PATCH /me (operation updateMe): changes the members that are sent (FR-ACC-09).
 *
 * The email address is not among the members: sending it is an unknown field, not a silent no-op.
 */
final readonly class UpdateMeAction
{
    public function __construct(
        private UpdateProfile $updateProfile,
        private ShowAccount $showAccount,
        private AccountResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);

        $fields = new Fields(RequestBody::of($request), ['displayName', 'avatar', 'locale', 'timeZone']);
        $change = new ProfileChange(
            $fields->makeOptional('displayName', DisplayName::fromInput(...)),
            $fields->makeOptional('avatar', Avatar::fromInput(...)),
            $fields->makeOptional('locale', Locale::fromInput(...)),
            $fields->makeOptional('timeZone', TimeZone::fromInput(...)),
        );
        if ($fields->hasErrors()) {
            throw $fields->failure();
        }

        $this->updateProfile->execute($userId, $change);

        return $this->responder->respond($this->showAccount->execute($userId));
    }
}
