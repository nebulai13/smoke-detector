<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use JsonException;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\DayEventType;
use UnexpectedValueException;
use ValueError;

/**
 * The celebration events of a day as `day_summaries.events` holds them: JSON text such as
 * `[{"type":"checked_in"},{"type":"under_limit_day","value":3}]`. An event without a value has no
 * "value" member; 0 is a value.
 *
 * The same shape is the DayEvent schema of the API, so the export passes it on as it is.
 */
final class DayEventsJson
{
    /**
     * @param list<DayEvent> $events
     */
    public static function encode(array $events): string
    {
        return json_encode(self::toArrays($events), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<DayEvent>
     *
     * @throws UnexpectedValueException For text that is not a list of events: a damaged row.
     */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new UnexpectedValueException('The events of a day are not JSON.', 0, $failure);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new UnexpectedValueException('The events of a day are not a list.');
        }

        $events = [];
        foreach ($decoded as $event) {
            $events[] = self::event($event);
        }

        return $events;
    }

    /**
     * @param list<DayEvent> $events
     *
     * @return list<array{type: string, value?: int}>
     */
    public static function toArrays(array $events): array
    {
        $arrays = [];
        foreach ($events as $event) {
            $arrays[] = $event->value === null
                ? ['type' => $event->type->value]
                : ['type' => $event->type->value, 'value' => $event->value];
        }

        return $arrays;
    }

    private static function event(mixed $event): DayEvent
    {
        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            throw new UnexpectedValueException('A stored day event has no type.');
        }
        $value = $event['value'] ?? null;
        if (!is_int($value) && $value !== null) {
            throw new UnexpectedValueException('A stored day event has a value that is not a whole number.');
        }
        if (array_diff(array_keys($event), ['type', 'value']) !== []) {
            throw new UnexpectedValueException('A stored day event has members it should not have.');
        }

        try {
            return new DayEvent(DayEventType::from($event['type']), $value);
        } catch (ValueError $failure) {
            throw new UnexpectedValueException('A stored day event has an unknown type.', 0, $failure);
        }
    }
}
