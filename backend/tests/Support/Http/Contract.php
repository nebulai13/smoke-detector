<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Http;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * An OpenAPI document (normally api/openapi.yaml) as the judge of requests and responses.
 *
 * The methods return null for "conforms" and a description of the violation otherwise, so that
 * HttpTestCase decides what is a test failure.
 */
final class Contract
{
    /** @var array<string, self> Parsing the document takes longer than a test, so it is done once per file and run. */
    private static array $loaded = [];

    private function __construct(
        public readonly string $file,
        private readonly OpenApi $document,
        private readonly ServerRequestValidator $requests,
        private readonly ResponseValidator $responses,
    ) {
    }

    public static function fromFile(string $file): self
    {
        if (!isset(self::$loaded[$file])) {
            $builder = (new ValidatorBuilder())->fromYamlFile($file);
            $requests = $builder->getServerRequestValidator();
            self::$loaded[$file] = new self($file, $requests->getSchema(), $requests, $builder->getResponseValidator());
        }

        return self::$loaded[$file];
    }

    public function document(): OpenApi
    {
        return $this->document;
    }

    /**
     * The operation of the contract that the request addresses by path and method, valid or not.
     * Null when the contract has no such operation.
     */
    public function operation(ServerRequestInterface $request): ?OperationAddress
    {
        $finder = new PathFinder($this->document, (string) $request->getUri(), $request->getMethod());
        $matches = $finder->search();
        if (count($matches) > 1) {
            throw new RuntimeException('The contract has more than one operation for ' . self::describe($request) . '.');
        }

        return $matches[0] ?? null;
    }

    public function requestViolation(ServerRequestInterface $request): ?string
    {
        // The validator insists on a Content-Type and a body wherever the contract describes one,
        // also when the contract says "required: false". A request that leaves an optional body out
        // is therefore checked as if it carried an empty JSON object: everything else about it
        // (path, headers, cookies) is still validated as it is.
        if ($this->leavesOutAnOptionalBody($request)) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new Psr17Factory())->createStream('{}'));
        }

        try {
            $this->requests->validate($request);
        } catch (ValidationFailed $violation) {
            return self::explain($violation);
        }

        return null;
    }

    public function responseViolation(OperationAddress $operation, ResponseInterface $response): ?string
    {
        try {
            $this->responses->validate($operation, $response);
        } catch (ValidationFailed $violation) {
            return self::explain($violation);
        }

        return null;
    }

    /**
     * Checks data against one schema of components/schemas, for responses that belong to no operation.
     */
    public function schemaViolation(string $schemaName, mixed $data): ?string
    {
        $schema = $this->document->components?->schemas[$schemaName] ?? null;
        if (!$schema instanceof Schema) {
            throw new RuntimeException("The contract has no schema \"{$schemaName}\".");
        }

        try {
            (new SchemaValidator(SchemaValidator::VALIDATE_AS_RESPONSE))->validate($data, $schema);
        } catch (SchemaMismatch $violation) {
            return self::explain($violation);
        }

        return null;
    }

    private function leavesOutAnOptionalBody(ServerRequestInterface $request): bool
    {
        if ($request->hasHeader('Content-Type') || $request->getBody()->getSize() !== 0) {
            return false;
        }
        $operation = $this->operation($request);
        if ($operation === null) {
            return false;
        }

        $body = $this->document->paths->getPath($operation->path())?->getOperations()[$operation->method()]->requestBody ?? null;

        return $body instanceof RequestBody && $body->required !== true;
    }

    public static function describe(ServerRequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri()->getPath();
    }

    /**
     * The validator nests its reasons as previous exceptions. All of them, outermost first.
     */
    private static function explain(Throwable $violation): string
    {
        $reasons = [];
        for ($reason = $violation; $reason !== null; $reason = $reason->getPrevious()) {
            $text = $reason->getMessage();
            if ($reason instanceof SchemaMismatch && $reason->dataBreadCrumb() !== null) {
                $path = implode('.', array_map(
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '?',
                    $reason->dataBreadCrumb()->buildChain(),
                ));
                $text .= $path === '' ? '' : " (at {$path})";
            }
            $reasons[] = $text;
        }

        return implode(' <- ', $reasons);
    }
}
