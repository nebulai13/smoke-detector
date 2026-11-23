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
use SmokeDetector\Http\Action\HealthAction;
use SmokeDetector\Http\Action\Legal\ShowConsentVersionsAction;
use SmokeDetector\Http\Action\Logging\CheckInAction;
use SmokeDetector\Http\Action\Logging\ListDaysAction;
use SmokeDetector\Http\Action\Logging\ShowBaselineHistoryAction;
use SmokeDetector\Http\Action\Logging\ShowDashboardAction;
use SmokeDetector\Http\Action\Logging\SyncLogsAction;
use SmokeDetector\Http\Action\Me\DeleteMeAction;
use SmokeDetector\Http\Action\Me\ExportMeAction;
use SmokeDetector\Http\Action\Me\SavePlanAction;
use SmokeDetector\Http\Action\Me\ShowMeAction;
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
    }
}
