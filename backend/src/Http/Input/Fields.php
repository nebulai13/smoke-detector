<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use BackedEnum;
use LogicException;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;

/**
 * Reads the members of a request body one by one and collects what is wrong with them, so that an
 * action can answer with every error at once (422 "validation_failed").
 *
 * Nothing is converted: a number is not a string and "true" is not true. A member the operation
 * does not know is an error too, so a misspelled optional member is not silently ignored. A reader
 * returns null when the member is unusable (and has noted why). The action then does:
 *
 *     if ($email === null || $password === null || $fields->hasErrors()) {
 *         throw $fields->failure();
 *     }
 *
 * Deliberately mutable: it lives for one request and is the list of that request's errors.
 */
final class Fields
{
    private const int MAX_ECHOED_NAME_LENGTH = 64;

    /** @var array<string, FieldError> By field: the first error of a field is the one reported. */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $body    The JSON object of the request (RequestBody::of()).
     * @param list<string>            $allowed Every member the operation knows. A dot leads into an
     *                                         object: "consents.age18.version" is the member
     *                                         "version" of the object "age18" of the object "consents".
     */
    public function __construct(
        private readonly array $body,
        private readonly array $allowed,
    ) {
        $this->rejectUnknownMembers($body, []);
    }

    /**
     * @param array<array-key, mixed> $object An object of the request.
     * @param list<string>            $path   The names that lead to it. Empty for the body itself.
     */
    private function rejectUnknownMembers(array $object, array $path): void
    {
        foreach ($object as $name => $value) {
            $member = [...$path, (string) $name];
            if ($this->isDeclared($member)) {
                continue;
            }
            if (!$this->isDeclaredObject($member)) {
                $this->reject(self::echoed(implode('.', $member)), ErrorCode::UnknownField, 'Unknown field.');
            } elseif (is_array($value) && !array_is_list($value)) {
                // An object the operation knows: its members are checked in turn. When it is not an
                // object at all, that is reported where one of its members is read.
                $this->rejectUnknownMembers($value, $member);
            }
        }
    }

    /**
     * @param list<string> $path
     */
    private function isDeclared(array $path): bool
    {
        foreach ($this->allowed as $allowed) {
            if (explode('.', $allowed) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a declared member lies inside what the path names, name for name.
     *
     * @param list<string> $path
     */
    private function isDeclaredObject(array $path): bool
    {
        foreach ($this->allowed as $allowed) {
            $names = explode('.', $allowed);
            if (count($names) > count($path) && array_slice($names, 0, count($path)) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * A required string, exactly as it was sent.
     */
    public function string(string $field): ?string
    {
        [$present, $value] = $this->read($field);
        if (!$present) {
            return null;
        }
        if (!is_string($value)) {
            $this->reject($field, ErrorCode::WrongType, 'Must be a string.');

            return null;
        }

        return $value;
    }

    /**
     * A string that may be left out. Null is not "left out": it is not a string.
     */
    public function optionalString(string $field): ?string
    {
        return $this->has($field) ? $this->string($field) : null;
    }

    public function boolean(string $field): ?bool
    {
        [$present, $value] = $this->read($field);
        if (!$present) {
            return null;
        }
        if (!is_bool($value)) {
            $this->reject($field, ErrorCode::WrongType, 'Must be true or false.');

            return null;
        }

        return $value;
    }

    /**
     * A required whole number. 12.0 and "12" are not one.
     */
    public function integer(string $field): ?int
    {
        [$present, $value] = $this->read($field);
        if (!$present) {
            return null;
        }
        if (!is_int($value)) {
            $this->reject($field, ErrorCode::WrongType, 'Must be a whole number.');

            return null;
        }

        return $value;
    }

    /**
     * A whole number that may be left out. Null is not "left out": it is not a number.
     */
    public function optionalInteger(string $field): ?int
    {
        return $this->has($field) ? $this->integer($field) : null;
    }

    /**
     * A required string that is one of the values of an enum.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum An enum with string values.
     *
     * @return T|null
     */
    public function enum(string $field, string $enum): ?BackedEnum
    {
        $value = $this->string($field);
        if ($value === null) {
            return null;
        }

        $case = $enum::tryFrom($value);
        if ($case === null) {
            $allowed = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
            $this->reject($field, ErrorCode::InvalidValue, 'Must be one of: ' . implode(', ', $allowed) . '.');
        }

        return $case;
    }

    /**
     * A required string, turned into a value object.
     *
     * @template T
     *
     * @param callable(string): T $factory Throws InvalidValue, with the code and a message for the
     *                                     client, when the string is not acceptable. Anything
     *                                     else it throws is a bug and is passed on.
     *
     * @return T|null
     */
    public function make(string $field, callable $factory): mixed
    {
        $value = $this->string($field);
        if ($value === null) {
            return null;
        }

        try {
            return $factory($value);
        } catch (InvalidValue $rejected) {
            $this->rejectIf($field, $rejected);

            return null;
        }
    }

    /**
     * @template T
     *
     * @param callable(string): T $factory
     *
     * @return T|null Null when the member was left out, and when it is not acceptable (see hasErrors()).
     */
    public function makeOptional(string $field, callable $factory): mixed
    {
        return $this->has($field) ? $this->make($field, $factory) : null;
    }

    /**
     * Notes an error the action found itself.
     */
    public function reject(string $field, ErrorCode $code, string $message): void
    {
        $this->errors[$field] ??= new FieldError($field, $code, $message);
    }

    /**
     * @param InvalidValue|null $violation What is wrong, or null when nothing is.
     */
    public function rejectIf(string $field, ?InvalidValue $violation): void
    {
        if ($violation !== null) {
            $this->errors[$field] ??= $violation->forField($field);
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The exception to throw when something is wrong.
     */
    public function failure(): ValidationFailed
    {
        if ($this->errors === []) {
            throw new LogicException('There is no validation error to report.');
        }

        return new ValidationFailed(array_values($this->errors));
    }

    private function has(string $field): bool
    {
        [$container, $name] = $this->locate($field, false);

        return $container !== null && array_key_exists($name, $container);
    }

    /**
     * @return array{bool, mixed} Whether the member is there, and its value. When it is not, the
     *                            reason is noted.
     */
    private function read(string $field): array
    {
        [$container, $name] = $this->locate($field, true);
        if ($container === null) {
            return [false, null];
        }
        if (!array_key_exists($name, $container)) {
            $this->reject($field, ErrorCode::Required, 'Is required.');

            return [false, null];
        }

        return [true, $container[$name]];
    }

    /**
     * Walks to the object that holds the member.
     *
     * @param bool $noteWhy Whether to note the reason when an object on the way is missing or is
     *                      not an object. One error for the object, not one per member of it.
     *
     * @return array{array<array-key, mixed>|null, string} What holds the member (null when the way
     *                                                     to it ends early), and the member's own name.
     */
    private function locate(string $field, bool $noteWhy): array
    {
        if (!in_array($field, $this->allowed, true)) {
            throw new LogicException("The field \"{$field}\" is not declared for this operation.");
        }

        $objects = explode('.', $field);
        $name = array_pop($objects);
        $container = $this->body;
        $path = [];
        foreach ($objects as $object) {
            $path[] = $object;
            if (!array_key_exists($object, $container)) {
                if ($noteWhy) {
                    $this->reject(implode('.', $path), ErrorCode::Required, 'Is required.');
                }

                return [null, $name];
            }
            $value = $container[$object];
            // A JSON object arrives as an array with names as keys. An empty one is an empty array.
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                if ($noteWhy) {
                    $this->reject(implode('.', $path), ErrorCode::WrongType, 'Must be an object.');
                }

                return [null, $name];
            }
            $container = $value;
        }

        return [$container, $name];
    }

    private static function echoed(string $name): string
    {
        return mb_strcut($name, 0, self::MAX_ECHOED_NAME_LENGTH, 'UTF-8');
    }
}
