<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Contract;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Tests\Support\Paths;
use Symfony\Component\Yaml\Yaml;

/**
 * Rules for the contract file api/openapi.yaml itself (HLD AD-4). The contract tests can only be as
 * strict as the contract, so the conventions that make it strict are checked here.
 */
final class OpenApiDocumentTest extends TestCase
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public function test_the_document_is_openapi_3_0_3(): void
    {
        // Not 3.1: the PHP validator and the three client generators support 3.0 fully (HLD AD-4).
        self::assertSame('3.0.3', self::document()['openapi'] ?? null);
        // The 3.1 way of saying "nullable" would be read as an invalid type by 3.0 tools.
        self::assertDoesNotMatchRegularExpression('/^\s*type:\s*\[/m', (string) file_get_contents(Paths::openApi()));
    }

    public function test_the_api_is_served_below_the_versioned_base_path(): void
    {
        self::assertSame('/api/v1', self::at(self::document(), 'servers', '0', 'url'));
    }

    public function test_every_operation_needs_a_bearer_token_unless_it_says_otherwise(): void
    {
        $document = self::document();

        self::assertSame([['bearerAuth' => []]], $document['security'] ?? null);
        self::assertSame('http', self::at($document, 'components', 'securitySchemes', 'bearerAuth', 'type'));
        self::assertSame('bearer', self::at($document, 'components', 'securitySchemes', 'bearerAuth', 'scheme'));

        foreach (self::operations() as $name => $operation) {
            // Only "no security at all" or the default: an operation cannot invent a weaker scheme.
            self::assertContains($operation['security'] ?? 'default', ['default', []], $name);
        }
    }

    public function test_every_object_schema_is_closed(): void
    {
        // Without additionalProperties: false a response with an undocumented field would still
        // conform, and the contract tests could not notice it.
        $open = [];
        self::collectOpenObjects(self::document(), '#', $open);

        self::assertSame([], $open, 'These object schemas lack "additionalProperties: false".');
    }

    public function test_an_object_schema_without_a_type_but_with_properties_counts_as_an_object(): void
    {
        // "properties" without "type: object" still describes an object, and it is just as open.
        $open = [];
        self::collectOpenObjects([
            'Untyped' => ['properties' => ['a' => ['type' => 'string']]],
            'UntypedClosed' => ['properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
            'Typed' => ['type' => 'object', 'properties' => []],
        ], '#', $open);

        self::assertSame(['#/Untyped', '#/Typed'], $open);
    }

    public function test_a_property_that_is_named_properties_is_not_taken_for_an_object_schema(): void
    {
        // Inside a "properties" map the keys are field names, not keywords: a field called
        // "properties" does not make the map an object schema.
        $open = [];
        self::collectOpenObjects([
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'properties' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ], '#', $open);

        self::assertSame([], $open);
    }

    public function test_every_operation_lists_the_internal_error_and_service_unavailable_responses(): void
    {
        // Any request can fail on the server or meet a database that is down, so every client must
        // be told what those answers look like.
        foreach (self::operations() as $name => $operation) {
            self::assertSame(
                ['#/components/responses/InternalError', '#/components/responses/ServiceUnavailable'],
                [self::at($operation, 'responses', '500', '$ref'), self::at($operation, 'responses', '503', '$ref')],
                $name,
            );
        }
    }

    public function test_unauthorized_always_names_the_scheme_and_too_many_requests_always_says_when_to_retry(): void
    {
        // The code always sends these headers (Problem::unauthorized, Problem::rateLimited), so the
        // contract requires them and every HTTP test checks that they are there. A 503 sends
        // Retry-After only when it can tell (not when the database cannot be reached): optional there.
        $responses = self::at(self::document(), 'components', 'responses');
        self::assertIsArray($responses);

        self::assertTrue(self::at($responses, 'Unauthorized', 'headers', 'WWW-Authenticate', 'required'));
        self::assertTrue(self::at($responses, 'TooManyRequests', 'headers', 'Retry-After', 'required'));
    }

    public function test_every_operation_has_a_unique_operation_id(): void
    {
        $ids = [];
        foreach (self::operations() as $name => $operation) {
            self::assertIsString($operation['operationId'] ?? null, "{$name} has no operationId.");
            self::assertMatchesRegularExpression('/\A[a-z][A-Za-z0-9]+\z/', $operation['operationId'], $name);
            $ids[] = $operation['operationId'];
        }

        self::assertNotSame([], $ids);
        self::assertSame($ids, array_unique($ids), 'The generated clients name their methods after the operationId.');
    }

    public function test_every_error_response_is_problem_details(): void
    {
        $checked = 0;
        foreach (self::operations() as $name => $operation) {
            self::assertIsArray($operation['responses'] ?? null, $name);
            foreach ($operation['responses'] as $status => $response) {
                $response = self::resolve($response);
                $content = $response['content'] ?? [];
                self::assertIsArray($content);
                $where = "{$name} response {$status}";

                self::assertMatchesRegularExpression('/\A[1-5][0-9][0-9]\z/', (string) $status, "{$where}: list each status code.");
                if ((int) $status >= 400) {
                    self::assertSame(['application/problem+json'], array_keys($content), $where);
                    $schema = self::at($content, 'application/problem+json', 'schema', '$ref');
                    self::assertContains(
                        $schema,
                        ['#/components/schemas/Problem', '#/components/schemas/ValidationProblem'],
                        $where,
                    );
                } elseif ($content !== []) {
                    self::assertSame(['application/json'], array_keys($content), $where);
                }
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_the_reusable_error_responses_exist_and_are_problem_details(): void
    {
        $responses = self::at(self::document(), 'components', 'responses');
        self::assertIsArray($responses);

        self::assertSame(
            [
                'BadRequest',
                'Unauthorized',
                'Forbidden',
                'NotFound',
                'MethodNotAllowed',
                'PayloadTooLarge',
                'UnsupportedMediaType',
                'ValidationFailed',
                'TooManyRequests',
                'InternalError',
                'ServiceUnavailable',
                'PlanRequired',
                'DayNotOpen',
                'InvalidToken',
                'InvalidCredentials',
                'WrongSignUpPassword',
                'InvalidRefreshToken',
                'UnauthorizedOrWrongPassword',
                'InviteNotFound',
                'FriendRequestRefused',
                'FriendLimitReached',
                'FriendRequestNotFound',
                'FriendNotFound',
                'BlockNotFound',
                'DuelNotFound',
                'DuelNotOpen',
                'DuelRefused',
            ],
            array_keys($responses),
        );
        foreach ($responses as $name => $response) {
            self::assertIsArray($response);
            $expected = $name === 'ValidationFailed' ? 'ValidationProblem' : 'Problem';
            self::assertSame(
                "#/components/schemas/{$expected}",
                self::at($response, 'content', 'application/problem+json', 'schema', '$ref'),
                (string) $name,
            );
        }
    }

    public function test_a_problem_has_type_title_and_status_and_a_slug_as_type(): void
    {
        $problem = self::at(self::document(), 'components', 'schemas', 'Problem');
        self::assertIsArray($problem);

        self::assertSame(['type', 'title', 'status'], $problem['required'] ?? null);
        self::assertSame('^[a-z][a-z0-9_]*$', self::at($problem, 'properties', 'type', 'pattern'));
    }

    public function test_a_validation_problem_lists_field_code_and_message(): void
    {
        $document = self::document();

        self::assertSame(
            ['type', 'title', 'status', 'errors'],
            self::at($document, 'components', 'schemas', 'ValidationProblem', 'required'),
        );
        self::assertSame(
            '#/components/schemas/FieldError',
            self::at($document, 'components', 'schemas', 'ValidationProblem', 'properties', 'errors', 'items', '$ref'),
        );
        self::assertSame(['field', 'code', 'message'], self::at($document, 'components', 'schemas', 'FieldError', 'required'));
    }

    public function test_the_codes_of_a_field_error_are_exactly_the_ones_the_code_can_send(): void
    {
        // Clients translate by this code, so the list in the contract is the list in the code.
        self::assertSame(
            array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases()),
            self::at(self::document(), 'components', 'schemas', 'FieldError', 'properties', 'code', 'enum'),
        );
    }

    public function test_the_contract_describes_a_consent_version_exactly_as_the_settings_accept_one(): void
    {
        // GET /legal/consents answers the settings. What the contract promises about a version
        // (length, characters) must therefore be what the settings allow, no more and no less.
        $candidates = [
            'a', '7', '2026-11-17', 'test-privacy-v2', 'V1.0_rc-1', str_repeat('a', 32),
            '', str_repeat('a', 33), '-a', '.a', '_a', 'a b', ' a', "a\n", 'a/b', 'ä1',
        ];
        foreach (['age18', 'art9', 'privacy'] as $text) {
            $schema = self::at(self::document(), 'components', 'schemas', 'ConsentVersions', 'properties', $text);
            self::assertIsArray($schema);
            self::assertSame(['string', 1, 32], [$schema['type'] ?? null, $schema['minLength'] ?? null, $schema['maxLength'] ?? null], $text);
            $pattern = $schema['pattern'] ?? null;
            self::assertIsString($pattern, $text);

            foreach ($candidates as $candidate) {
                try {
                    new ConsentVersions($candidate, $candidate, $candidate);
                    $accepted = true;
                } catch (InvalidArgumentException) {
                    $accepted = false;
                }
                // (D: "$" is the very end, as in the regular expressions of JSON Schema.)
                self::assertSame($accepted, preg_match('~' . $pattern . '~D', $candidate) === 1, "{$text}: " . json_encode($candidate));
            }
        }
        // And a registration cannot name a longer version than one that can be in force.
        self::assertSame(
            [1, 32],
            [
                self::at(self::document(), 'components', 'schemas', 'GivenConsent', 'properties', 'version', 'minLength'),
                self::at(self::document(), 'components', 'schemas', 'GivenConsent', 'properties', 'version', 'maxLength'),
            ],
        );
    }

    public function test_the_contract_never_promises_that_a_mail_was_sent_when_the_request_is_answered(): void
    {
        // No request sends its mail: it is queued, and the cron job sends it a minute or two later
        // (HLD §4.5 "Mail"). A client that reads "a mail was sent" tells the user to look at once.
        $document = self::document();
        $notes = self::at($document, 'info', 'description');
        self::assertIsString($notes);
        self::assertStringContainsString('**Mails take a minute or two.**', $notes);
        self::assertMatchesRegularExpression('/show[^.]*the mail can take a minute/i', $notes);

        $text = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(Paths::openApi()));
        self::assertDoesNotMatchRegularExpression('/\b(mail|link) (was|has been) sent\b|we sent a mail|sent (immediately|at once|right away)/i', $text);
        foreach (['POST /auth/register', 'POST /auth/resend-verification', 'POST /auth/password-reset/request', 'POST /auth/login'] as $name) {
            $description = self::operations()[$name]['description'] ?? null;
            self::assertIsString($description, $name);
            self::assertMatchesRegularExpression('/within a\s+minute or two/', $description, $name);
        }
    }

    public function test_the_notes_say_when_a_mail_that_cannot_be_delivered_is_given_up(): void
    {
        // The sender gives a mail up after the fifth failed try, about 80 minutes after the request
        // (SendQueuedMails), and not only when its link has expired. A client developer who reads
        // "tried for as long as the link is valid" would promise a confirmation mail for 24 hours.
        $notes = self::at(self::document(), 'info', 'description');
        self::assertIsString($notes);
        $notes = (string) preg_replace('/\s+/', ' ', $notes);

        self::assertStringContainsString('1, 5, 15 and 60 minutes later', $notes);
        self::assertStringContainsString('dropped after the fifth failed try', $notes);
        self::assertStringNotContainsString('dropped when it could not be delivered while its link is still valid', $notes);
    }

    public function test_the_export_names_a_queued_mail_by_the_templates_the_application_has(): void
    {
        $schema = self::at(self::document(), 'components', 'schemas', 'ExportedQueuedMail');
        self::assertIsArray($schema);

        self::assertSame(['template', 'createdAt', 'attempts'], $schema['required'] ?? null);
        // Neither the address nor anything of the content.
        self::assertSame(['template', 'createdAt', 'attempts'], array_keys((array) ($schema['properties'] ?? [])));
        self::assertSame(
            array_map(static fn (MailKind $kind): string => $kind->value, MailKind::cases()),
            self::at($schema, 'properties', 'template', 'enum'),
        );
        self::assertContains('queuedMails', (array) self::at(self::document(), 'components', 'schemas', 'AccountExport', 'required'));
    }

    public function test_health_reports_the_cron_heartbeat_as_a_time_or_null(): void
    {
        $schema = self::at(self::document(), 'components', 'schemas', 'Health', 'properties', 'cronLastRunAt');
        self::assertIsArray($schema);

        self::assertSame(['string', 'date-time', true], [$schema['type'] ?? null, $schema['format'] ?? null, $schema['nullable'] ?? null]);
        // The cron job exists now: the notes must not say that the member is always null.
        $notes = self::at(self::document(), 'info', 'description');
        self::assertIsString($notes);
        self::assertStringNotContainsString('until the cron job exists', (string) preg_replace('/\s+/', ' ', $notes));
        self::assertStringContainsString('`cronLastRunAt`', $notes);
    }

    public function test_no_personal_or_health_data_travels_in_a_url(): void
    {
        // Query strings end up in server logs, browser histories and referrers. So the API has no
        // query parameters, except the ones listed here with their reason. A new one is added only
        // after the same question: does it say anything about a person?
        $reviewed = [
            // The two ends of a range of calendar days. Whose days they are is in the token, and how
            // the days went is in the answer.
            'GET /days' => ['from', 'to'],
        ];

        $found = [];
        foreach (self::operations() as $name => $operation) {
            $parameters = $operation['parameters'] ?? [];
            self::assertIsArray($parameters);
            foreach ($parameters as $parameter) {
                $parameter = self::resolve($parameter);
                if (($parameter['in'] ?? null) === 'query') {
                    $found[$name][] = $parameter['name'] ?? null;
                }
                // A path is logged like a query string: nothing but a calendar day goes into one.
                if (($parameter['in'] ?? null) === 'path') {
                    self::assertSame(['date', 'date'], [$parameter['name'] ?? null, self::at($parameter, 'schema', 'format')], "{$name} has a path parameter that is not a date.");
                }
            }
        }

        self::assertSame($reviewed, $found);
        foreach ($reviewed['GET /days'] as $name) {
            $parameter = array_values(array_filter(
                array_map(self::resolve(...), (array) (self::operations()['GET /days']['parameters'] ?? [])),
                static fn (array $parameter): bool => ($parameter['name'] ?? null) === $name,
            ))[0] ?? [];
            self::assertSame('date', self::at($parameter, 'schema', 'format'), "{$name} must be a date and nothing else.");
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function document(): array
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @return array<string, array<array-key, mixed>> "GET /health" => operation.
     */
    private static function operations(): array
    {
        $paths = self::document()['paths'] ?? null;
        self::assertIsArray($paths);

        $operations = [];
        foreach ($paths as $path => $item) {
            self::assertIsArray($item);
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    self::assertIsArray($item[$method]);
                    $operations[strtoupper($method) . ' ' . $path] = $item[$method];
                }
            }
        }

        return $operations;
    }

    /**
     * Follows a local "$ref" (one level, as the document uses them).
     *
     * @return array<array-key, mixed>
     */
    private static function resolve(mixed $node): array
    {
        self::assertIsArray($node);
        $reference = $node['$ref'] ?? null;
        if (!is_string($reference)) {
            return $node;
        }

        self::assertStringStartsWith('#/', $reference);
        $target = self::at(self::document(), ...explode('/', substr($reference, 2)));
        self::assertIsArray($target, "{$reference} does not exist.");

        return $target;
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function at(array $node, string ...$keys): mixed
    {
        $value = $node;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $open          Collects the paths of object schemas that are not closed.
     * @param bool                    $isPropertyMap The node is the "properties" map of a schema: its keys
     *                                               are field names and its values schemas, not keywords.
     */
    private static function collectOpenObjects(array $node, string $path, array &$open, bool $isPropertyMap = false): void
    {
        if (!$isPropertyMap) {
            // An object schema says type: object, or has no type but properties (which only objects have).
            $type = $node['type'] ?? null;
            $isObject = $type === 'object' || ($type === null && is_array($node['properties'] ?? null));
            if ($isObject && ($node['additionalProperties'] ?? null) !== false) {
                $open[] = $path;
            }
        }
        foreach ($node as $key => $child) {
            if (is_array($child)) {
                self::collectOpenObjects($child, "{$path}/{$key}", $open, !$isPropertyMap && $key === 'properties');
            }
        }
    }
}
