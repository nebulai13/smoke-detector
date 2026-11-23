<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use Closure;
use ErrorException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\ResponseEmitter;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Config\SettingsLoader;
use SmokeDetector\Http\Middleware\SecurityHeadersMiddleware;
use SmokeDetector\Http\Problem\Problem;
use Throwable;

/**
 * The one way into the application for a web request. public/index.php only calls run().
 *
 * The front controller file sits in different places (backend/public/index.php in the repository,
 * public/api/index.php in a release, HLD §8), so everything it has to do lives here instead.
 */
final readonly class FrontController
{
    private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /** Problem::internalError() as text, for when nothing can be built any more. */
    private const string FATAL_ERROR_BODY = '{"type":"internal_error","title":"Internal server error","status":500}';

    /** @var Closure(string): void */
    private Closure $report;

    /** The directory that holds src/ and vendor/: where a release finds its settings (ReleaseLayout). */
    private string $appRoot;

    /**
     * @param (Closure(string): void)|null $report  Where a failed start is reported. PHP's error log by default:
     *                                              the application log is not available before the settings are.
     * @param string|null                  $appRoot The application root. By default the one this class lies in,
     *                                              wherever the front controller file is.
     */
    public function __construct(?Closure $report = null, ?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?? dirname(__DIR__, 2);
        $this->report = $report ?? static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * Answers the request of the current PHP process and sends the response.
     */
    public function run(): void
    {
        // PHP must never print an error into a JSON response, and never say what it runs on.
        ini_set('display_errors', '0');
        header_remove('X-Powered-By');
        // The server's PHP runs in CET. Nothing in this process may produce local time by accident.
        date_default_timezone_set('UTC');

        // A warning or notice is a bug, and becomes a 500 like any exception, instead of a response
        // that looks fine and is built on a wrong value. Deprecations are left to PHP's log: they
        // announce a future problem and must not take the API down after a PHP update.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        // Out of memory, time limit: PHP stops without running any handler above. Answer at least in the
        // usual format. Plain PHP functions and a fixed text only: after a fatal error there may be no
        // memory left to load a class or to build a response object.
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0 || headers_sent()) {
                return;
            }

            http_response_code(500);
            header('Content-Type: application/problem+json');
            foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) {
                header("{$name}: {$value}");
            }
            echo self::FATAL_ERROR_BODY;
        });

        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

        // Apache hands variables set with SetEnv to PHP in $_SERVER, the shell in getenv().
        $response = $this->handle($request, getenv() + $_SERVER);

        // With its length, a client knows the response is complete as soon as the last byte is there.
        // (Not for 204: a response without content must not announce a length, RFC 9110.)
        $size = $response->getBody()->getSize();
        if ($size !== null && $response->getStatusCode() !== 204) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }
        (new ResponseEmitter())->emit($response);

        // Nothing happens after the response. The production server's PHP (cgi-fcgi, HLD §8) has no
        // way to hand the response over and keep working: whatever a request does, the client
        // waits for. Slow work that must not show in the response time, such as sending mail, is
        // therefore never done in a request at all: it is queued for the cron job (MailOutbox).
    }

    /**
     * Builds the application from the environment and lets it answer the request.
     *
     * @param array<array-key, mixed> $environment Name => value of the environment variables.
     */
    public function handle(ServerRequestInterface $request, array $environment): ResponseInterface
    {
        try {
            $settings = (new SettingsLoader())->load($environment, $this->appRoot);
            $container = (new ContainerFactory())->create($settings);
            $app = (new AppFactory())->create($container);
        } catch (Throwable $failure) {
            // Invalid or missing settings. The message names the setting, never its value.
            ($this->report)('The application could not start: ' . $failure::class . ': ' . $failure->getMessage());

            return $this->cannotAnswer();
        }

        // Everything thrown from here on is turned into a response by the application's error handler.
        return $app->handle($request);
    }

    /**
     * The response when the application itself is not available to produce one.
     */
    private function cannotAnswer(): ResponseInterface
    {
        $factory = new Psr17Factory();
        $response = (new JsonResponder($factory, $factory))->problem(Problem::internalError());

        return SecurityHeadersMiddleware::secure($response);
    }
}
