<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface;
use SmokeDetector\Http\Action\Auth\ConfirmPasswordResetAction;
use SmokeDetector\Http\Action\Auth\LoginAction;
use SmokeDetector\Http\Action\Auth\LogoutAction;
use SmokeDetector\Http\Action\Auth\RefreshAction;
use SmokeDetector\Http\Action\Auth\RegisterAction;
use SmokeDetector\Http\Action\Auth\RequestPasswordResetAction;
use SmokeDetector\Http\Action\Auth\ResendVerificationAction;
use SmokeDetector\Http\Action\Auth\VerifyEmailAction;
use SmokeDetector\Http\Action\Competition\AcceptDuelAction;
use SmokeDetector\Http\Action\Competition\CancelDuelAction;
use SmokeDetector\Http\Action\Competition\DeclineDuelAction;
use SmokeDetector\Http\Action\Competition\ListDuelsAction;
use SmokeDetector\Http\Action\Competition\ProposeDuelAction;
use SmokeDetector\Http\Action\Competition\ShowDuelAction;
use SmokeDetector\Http\Action\Competition\ShowLeagueAction;
use SmokeDetector\Http\Action\Competition\ShowXpLeagueAction;
use SmokeDetector\Http\Action\Friends\AcceptFriendRequestAction;
use SmokeDetector\Http\Action\Friends\BlockUserAction;
use SmokeDetector\Http\Action\Friends\DeclineFriendRequestAction;
use SmokeDetector\Http\Action\Friends\ListBlockedUsersAction;
use SmokeDetector\Http\Action\Friends\ListFriendsAction;
use SmokeDetector\Http\Action\Friends\RemoveFriendAction;
use SmokeDetector\Http\Action\Friends\RenewInviteAction;
use SmokeDetector\Http\Action\Friends\SendFriendRequestAction;
use SmokeDetector\Http\Action\Friends\ShowInviteAction;
use SmokeDetector\Http\Action\Friends\UnblockUserAction;
use SmokeDetector\Http\Action\Friends\WithdrawFriendRequestAction;
use SmokeDetector\Http\Action\HealthAction;
use SmokeDetector\Http\Action\Legal\ShowConsentVersionsAction;
use SmokeDetector\Http\Action\Logging\CheckInAction;
use SmokeDetector\Http\Action\Logging\ListDaysAction;
use SmokeDetector\Http\Action\Logging\ShowBaselineHistoryAction;
use SmokeDetector\Http\Action\Logging\ShowDashboardAction;
use SmokeDetector\Http\Action\Feed\ReactAction;
use SmokeDetector\Http\Action\Feed\ShowFeedAction;
use SmokeDetector\Http\Action\Progress\ListBadgesAction;
use SmokeDetector\Http\Action\Progress\ListHealthMilestonesAction;
use SmokeDetector\Http\Action\Logging\SyncLogsAction;
use SmokeDetector\Http\Action\Me\DeleteMeAction;
use SmokeDetector\Http\Action\Me\ExportMeAction;
use SmokeDetector\Http\Action\Me\SavePlanAction;
use SmokeDetector\Http\Action\Me\SaveSettingsAction;
use SmokeDetector\Http\Action\Me\ShowMeAction;
use SmokeDetector\Http\Action\Me\ShowSettingsAction;
use SmokeDetector\Http\Action\Me\UpdateMeAction;
use SmokeDetector\Http\Middleware\AuthenticationMiddleware;

/**
 * Every route of the API, in one place. Paths are relative to BASE_PATH and are the paths of
 * api/openapi.yaml. A route names its action class, which the container builds when it is called.
 *
 * There are two kinds, and a route belongs to the one its operation in the contract says:
 * public() for "security: []", authenticated() for everything else. The tests compare both lists
 * with the contract (RoutesMatchContractTest, AccessControlTest), so a route cannot end up on the
 * wrong side unnoticed.
 */
final readonly class Routes
{
    /** The API is versioned in its path (NFR-12). Also the "servers" entry of api/openapi.yaml. */
    public const string BASE_PATH = '/api/v1';

    /**
     * @param App<ContainerInterface> $app
     */
    public function register(App $app): void
    {
        $this->public($app);

        // The middleware runs before the action of every route in the group, and answers 401
        // "unauthorized" without a valid access token. The action reads the user with
        // AuthenticatedUser::id($request).
        // (Slim binds a group's closure to the container, so this object is handed in by name.)
        $self = $this;
        $app->group('', function (RouteCollectorProxyInterface $routes) use ($self): void {
            $self->authenticated($routes);
        })->add(AuthenticationMiddleware::class);
    }

    /**
     * Operations anybody may call: they are how a client gets a token in the first place, or what
     * it has to know before that.
     *
     * @param App<ContainerInterface> $routes
     */
    private function public(App $routes): void
    {
        $routes->get('/health', HealthAction::class);
        // What an app needs before anybody has an account: which consent texts are in force.
        $routes->get('/legal/consents', ShowConsentVersionsAction::class);

        $routes->post('/auth/register', RegisterAction::class);
        $routes->post('/auth/verify-email', VerifyEmailAction::class);
        $routes->post('/auth/resend-verification', ResendVerificationAction::class);
        $routes->post('/auth/login', LoginAction::class);
        // Refresh and logout are authenticated by the refresh token itself, and must work when the
        // access token has expired: that is exactly when they are called.
        $routes->post('/auth/refresh', RefreshAction::class);
        $routes->post('/auth/logout', LogoutAction::class);
        $routes->post('/auth/password-reset/request', RequestPasswordResetAction::class);
        $routes->post('/auth/password-reset/confirm', ConfirmPasswordResetAction::class);
    }

    /**
     * Operations of a logged-in user. The user is always the one of the access token: no route
     * has a user id in its path.
     *
     * @param RouteCollectorProxyInterface<ContainerInterface|null> $routes
     */
    private function authenticated(RouteCollectorProxyInterface $routes): void
    {
        $routes->get('/me', ShowMeAction::class);
        $routes->patch('/me', UpdateMeAction::class);
        $routes->delete('/me', DeleteMeAction::class);
        $routes->put('/me/plan', SavePlanAction::class);
        $routes->get('/me/export', ExportMeAction::class);
        $routes->get('/me/dashboard', ShowDashboardAction::class);
        $routes->get('/me/baseline-history', ShowBaselineHistoryAction::class);

        // Logging, check-in and days (HLD §4.6).
        $routes->post('/logs/sync', SyncLogsAction::class);
        $routes->get('/days', ListDaysAction::class);
        $routes->post('/days/{date}/check-in', CheckInAction::class);

        // Friends (HLD §4.7). The other user's id is in the body, never in the path.
        $routes->get('/me/invite', ShowInviteAction::class);
        $routes->post('/me/invite', RenewInviteAction::class);
        $routes->get('/friends', ListFriendsAction::class);
        $routes->post('/friends/requests', SendFriendRequestAction::class);
        $routes->post('/friends/requests/accept', AcceptFriendRequestAction::class);
        $routes->post('/friends/requests/decline', DeclineFriendRequestAction::class);
        $routes->post('/friends/requests/withdraw', WithdrawFriendRequestAction::class);
        $routes->post('/friends/remove', RemoveFriendAction::class);
        $routes->post('/friends/block', BlockUserAction::class);
        $routes->post('/friends/unblock', UnblockUserAction::class);
        $routes->get('/friends/blocked', ListBlockedUsersAction::class);

        // Duels (HLD §4.8). The duel id and the friend's id are in the body, never in the path.
        $routes->get('/duels', ListDuelsAction::class);
        $routes->post('/duels', ProposeDuelAction::class);
        $routes->post('/duels/show', ShowDuelAction::class);
        $routes->post('/duels/accept', AcceptDuelAction::class);
        $routes->post('/duels/decline', DeclineDuelAction::class);
        $routes->post('/duels/cancel', CancelDuelAction::class);

        // Leagues (HLD §4.9). The kind of period and the period are in the query: they say nothing about a person.
        $routes->get('/leagues', ShowLeagueAction::class);
        $routes->get('/leagues/xp', ShowXpLeagueAction::class);

        // Badges and health milestones (HLD §4.10): the user's own.
        $routes->get('/me/badges', ListBadgesAction::class);
        $routes->get('/me/health-milestones', ListHealthMilestonesAction::class);

        // Settings (HLD §4.11): what friends see of the health milestones.
        $routes->get('/me/settings', ShowSettingsAction::class);
        $routes->put('/me/settings', SaveSettingsAction::class);

        // Activity feed (HLD §4.12): the user's and the friends' entries. Reactions (HLD §4.13): the
        // id of the entry is in the body, never in the path.
        $routes->get('/feed', ShowFeedAction::class);
        $routes->post('/feed/reactions', ReactAction::class);
    }
}
