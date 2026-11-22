<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use RuntimeException;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use SmokeDetector\Config\Settings;
use SmokeDetector\Http\Error\ErrorHandler;
use SmokeDetector\Http\Middleware\JsonBodyMiddleware;
use SmokeDetector\Http\Middleware\SecurityHeadersMiddleware;

/**
 * Builds the Slim application: routes, middleware and the error handler.
 *
 * The same application serves real requests (FrontController) and the HTTP tests (HttpTestCase).
 */
final readonly class AppFactory
{
    /**
     * @return App<ContainerInterface>
     */
    public function create(ContainerInterface $container): App
    {
        $settings = self::get($container, Settings::class);

        $app = new App(self::get($container, ResponseFactoryInterface::class), $container);
        $app->setBasePath(Routes::BASE_PATH);
        (new Routes())->register($app);

        $errors = new ErrorMiddleware(
            $app->getCallableResolver(),
            $app->getResponseFactory(),
            $settings->environment->exposesErrorDetails(),
            // Our error handler does the logging itself, without argument values.
            false,
            false,
        );
        $errors->setDefaultErrorHandler(self::get($container, ErrorHandler::class));

        // Slim runs the middleware that was added last first. From the outside in:
        //   security headers → error handling → routing → request body → action
        // So every response gets the headers, error responses included, an unknown route is a 404
        // before its body is looked at, and everything thrown further in becomes problem details.
        $app->add(self::get($container, JsonBodyMiddleware::class));
        $app->addRoutingMiddleware();
        $app->add($errors);
        $app->add(self::get($container, SecurityHeadersMiddleware::class));

        return $app;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function get(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        if (!$service instanceof $class) {
            throw new RuntimeException("The container has no {$class}.");
        }

        return $service;
    }
}
