<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\RateLimitKey;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;

/**
 * public/index.php end to end: a real PHP web server process and real HTTP, the one place where the
 * front controller file, the reading of PHP's globals and the sending of the response are exercised.
 *
 * Everything else about HTTP is tested in-process (HttpTestCase), which is faster and checks the
 * contract. The server inherits the environment of the test run, so it uses the test schema.
 */
final class FrontControllerScriptTest extends IntegrationTestCase
{
    /** @var resource|null */
    private mixed $server = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function test_a_request_over_http_is_answered_with_json_and_the_security_headers(): void
    {
        $base = $this->startServer();

        [$status, $headers, $body] = $this->get($base . '/api/v1/health');

        self::assertSame(200, $status);
        self::assertSame('{"status":"ok","version":"test-release","cronLastRunAt":null}', $body);
        self::assertSame('application/json', $headers['content-type'] ?? null);
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        // PHP does not announce itself.
        self::assertArrayNotHasKey('x-powered-by', $headers);
    }

    public function test_an_unknown_path_over_http_is_404_problem_details(): void
    {
        $base = $this->startServer();

        [$status, $headers, $body] = $this->get($base . '/api/v1/no/such/path?token=abc');

        self::assertSame(404, $status);
        self::assertSame('application/problem+json', $headers['content-type'] ?? null);
        self::assertSame('{"type":"not_found","title":"Not found","status":404}', $body);
    }

    public function test_a_fatal_php_error_is_still_answered_as_problem_details(): void
    {
        // A settings file that runs out of memory: a fatal error that no exception handler sees.
        $settings = sys_get_temp_dir() . '/smoke-detector-fatal-' . bin2hex(random_bytes(8)) . '.php';
        self::assertNotFalse(file_put_contents($settings, '<?php return str_repeat("x", 1 << 40);'));
        $this->files[] = $settings;
        $base = $this->startServer(['SETTINGS_FILE' => $settings]);

        [$status, $headers, $body] = $this->get($base . '/api/v1/health');

        self::assertSame(500, $status);
        self::assertSame('application/problem+json', $headers['content-type'] ?? null);
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertSame('{"type":"internal_error","title":"Internal server error","status":500}', $body);
    }

    public function test_an_application_that_cannot_start_answers_500_without_saying_why(): void
    {
        $base = $this->startServer(['JWT_SECRET' => 'LEAK-short']);

        [$status, $headers, $body] = $this->get($base . '/api/v1/health');

        self::assertSame(500, $status);
        self::assertSame('application/problem+json', $headers['content-type'] ?? null);
        self::assertSame('{"type":"internal_error","title":"Internal server error","status":500}', $body);
    }

    public function test_NFR_06_a_web_session_works_over_real_http_with_the_refresh_token_in_the_cookie_only(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        (new PdoUserRepository($this->sql()))->add(TestAccounts::user(TestData::uuid(1), 'anna@example.org', $now, $now));
        $base = $this->startServer();

        // Login: the cookie is really sent as a Set-Cookie header with all its attributes.
        [$status, $headers, $body] = $this->post($base . '/api/v1/auth/login', '{"email":"anna@example.org","password":"' . TestAccounts::PASSWORD . '","refreshIn":"cookie"}');
        self::assertSame(200, $status, $body);
        self::assertSame(1, preg_match('~\Arefresh_token=([A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}); Max-Age=5184000; Path=/api/v1/auth; Secure; HttpOnly; SameSite=Strict\z~', $headers['set-cookie'] ?? '', $cookie));
        self::assertStringNotContainsString($cookie[1] ?? '?', $body);
        self::assertSame((string) strlen($body), $headers['content-length'] ?? null);
        self::assertSame('no-store', $headers['cache-control'] ?? null);

        // Refresh: PHP hands the Cookie header of a real request on, without any body.
        [$status, $headers, $body] = $this->post($base . '/api/v1/auth/refresh', null, ['Cookie: theme=dark; refresh_token=' . ($cookie[1] ?? '')]);
        self::assertSame(200, $status, $body);
        self::assertSame(1, preg_match('~\Arefresh_token=([A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43});~', $headers['set-cookie'] ?? '', $rotated));
        self::assertNotSame($cookie[1] ?? null, $rotated[1] ?? null);
        self::assertStringContainsString('"accessToken":"', $body);
        self::assertStringNotContainsString('refreshToken', $body);

        // Logout: 204 without a body, and the cookie is cleared.
        [$status, $headers, $body] = $this->post($base . '/api/v1/auth/logout', null, ['Cookie: refresh_token=' . ($rotated[1] ?? '')]);
        self::assertSame(204, $status);
        self::assertSame('', $body);
        self::assertStringStartsWith('refresh_token=; Max-Age=0;', $headers['set-cookie'] ?? '');

        [$status] = $this->post($base . '/api/v1/auth/refresh', null, ['Cookie: refresh_token=' . ($rotated[1] ?? '')]);
        self::assertSame(401, $status);
    }

    public function test_FR_ACC_08_over_real_http_a_delete_request_brings_its_token_and_its_body(): void
    {
        // The two things only a real request shows: PHP hands the Authorization header on, and the
        // body of a DELETE request reaches the application.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        (new PdoUserRepository($this->sql()))->add(TestAccounts::user(TestData::uuid(1), 'anna@example.org', $now, $now));
        $base = $this->startServer();
        [$status, , $body] = $this->post($base . '/api/v1/auth/login', '{"email":"anna@example.org","password":"' . TestAccounts::PASSWORD . '","refreshIn":"body"}');
        self::assertSame(200, $status, $body);
        $session = json_decode($body, true);
        self::assertIsArray($session);
        self::assertIsString($session['accessToken'] ?? null);
        $authorization = ['Authorization: Bearer ' . $session['accessToken']];

        [$status, , $body] = $this->send($base . '/api/v1/me', ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10, 'header' => $authorization]);
        self::assertSame(200, $status, $body);
        self::assertStringContainsString('"email":"anna@example.org"', $body);

        // Without the body the token alone deletes nothing, and a wrong password does not either.
        [$status] = $this->request('DELETE', $base . '/api/v1/me', null, $authorization);
        self::assertSame(422, $status);
        [$status, $headers, $body] = $this->request('DELETE', $base . '/api/v1/me', '{"password":"not the password"}', $authorization);
        self::assertSame(401, $status);
        self::assertStringContainsString('"type":"invalid_credentials"', $body);
        self::assertArrayNotHasKey('www-authenticate', $headers);
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM users'));

        [$status, , $body] = $this->request('DELETE', $base . '/api/v1/me', '{"password":"' . TestAccounts::PASSWORD . '"}', $authorization);
        self::assertSame(204, $status, $body);
        self::assertSame('', $body);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM users'));

        [$status, $headers] = $this->send($base . '/api/v1/me', ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10, 'header' => $authorization]);
        self::assertSame(401, $status);
        self::assertSame('Bearer', $headers['www-authenticate'] ?? null);
    }

    public function test_NFR_05_over_real_http_the_rate_limit_counts_the_address_of_the_connection(): void
    {
        $base = $this->startServer();

        $this->post($base . '/api/v1/auth/password-reset/request', '{"email":"nobody@example.org"}', ['X-Forwarded-For: 198.51.100.77']);

        // 127.0.0.1 is where this test connects from. The header is ignored.
        self::assertSame(
            [
                RateLimitKey::of('mail.address', 'nobody@example.org'),
                RateLimitKey::of('mail.client', '127.0.0.1'),
                RateLimitKey::of('mail.pair', 'nobody@example.org', '127.0.0.1'),
            ],
            $this->sql()->column('SELECT `key` FROM rate_limits ORDER BY `key`'),
        );
    }

    /**
     * Starts PHP's built-in web server with public/index.php as the front controller.
     *
     * @param array<string, string> $environment Variables that differ from those of the test run.
     *
     * @return string The base URL of the server.
     */
    private function startServer(array $environment = []): string
    {
        // Ask the system for a free port, release it, and let the server take it.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', $address, Paths::backend() . '/public/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            Paths::backend() . '/public',
            $environment + getenv(),
        );
        self::assertIsResource($server);
        $this->server = $server;

        [$host, $port] = explode(':', $address) + [1 => '0'];
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen($host, (int) $port, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return 'http://' . $address;
            }
            usleep(50_000);
        }

        self::fail('The PHP web server did not start.');
    }

    /**
     * @return array{int, array<string, string>, string} Status, headers by lower-case name, body.
     */
    private function get(string $url): array
    {
        return $this->send($url, ['ignore_errors' => true, 'timeout' => 10]);
    }

    /**
     * @param string|null  $json    The body, or null for a request without one.
     * @param list<string> $headers "Name: value".
     *
     * @return array{int, array<string, string>, string} Status, headers by lower-case name, body.
     */
    private function post(string $url, ?string $json, array $headers = []): array
    {
        return $this->request('POST', $url, $json, $headers);
    }

    /**
     * @param string|null  $json    The body, or null for a request without one.
     * @param list<string> $headers "Name: value".
     *
     * @return array{int, array<string, string>, string} Status, headers by lower-case name, body.
     */
    private function request(string $method, string $url, ?string $json, array $headers = []): array
    {
        $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'header' => $headers];
        if ($json !== null) {
            $options['header'][] = 'Content-Type: application/json';
            $options['content'] = $json;
        }

        return $this->send($url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, array<string, string>, string}
     */
    private function send(string $url, array $options): array
    {
        $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
        self::assertIsString($body);
        // Set by file_get_contents() for an http:// URL.
        $lines = http_get_last_response_headers() ?? [];

        self::assertSame(1, preg_match('~\AHTTP/\S+ (\d{3})~', $lines[0] ?? '', $match));
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [(int) ($match[1] ?? 0), $headers, $body];
    }
}
