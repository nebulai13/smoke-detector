<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Validation;

/**
 * Why a field of the input was not accepted, as a stable slug for clients.
 *
 * This is the member "code" of every entry of a 422 body, and the enum of the schema FieldError in
 * api/openapi.yaml (a test compares the two). Clients translate by it, so a code is never renamed
 * or removed. The message next to it is English text for developers and may be reworded any time.
 */
enum ErrorCode: string
{
    /** The member is missing (or, for the body as a whole, nothing usable was sent). */
    case Required = 'required';
    /** The operation has no member of this name. */
    case UnknownField = 'unknown_field';
    /** Another JSON type than the contract says: a number where a string belongs, and so on. */
    case WrongType = 'wrong_type';
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    /** A number outside the range the contract gives. */
    case OutOfRange = 'out_of_range';
    /** Text that is not built as it must be: not an email address, forbidden characters. */
    case InvalidFormat = 'invalid_format';
    /** Not one of the values that can be chosen: an unknown avatar, language, time zone, mode. */
    case InvalidValue = 'invalid_value';
    /** A consent that was not given. */
    case NotAccepted = 'not_accepted';
    /** A reduce target that is not below the user's current baseline (FR-ACC-06). */
    case MustBeBelowBaseline = 'must_be_below_baseline';
    /** The starting baseline can be stated once only. */
    case BaselineAlreadySet = 'baseline_already_set';
    /** Goal mode and target contradict each other: "quit" with a target, "reduce" to 0. */
    case GoalTargetMismatch = 'goal_target_mismatch';
    /** The same thing was sent in two places, and nothing says which one is meant. */
    case Ambiguous = 'ambiguous';
    /** A consent to another version of a text than the one in force: the app has to be updated. */
    case OutdatedVersion = 'outdated_version';
}
