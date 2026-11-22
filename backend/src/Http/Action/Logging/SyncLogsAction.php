<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Logging\LogItem;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SyncLogs;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Logging\LoggingResponder;
use SmokeDetector\Http\RequestBody;

/**
 * POST /logs/sync (operation syncLogs, FR-LOG-02, FR-LOG-03).
 *
 * Checked here: the shape of the batch and of every item. A malformed item refuses the whole
 * request, with every error of the batch in one answer, in the order of the items. Checked by the
 * use case: what needs stored data. Those answers are per item, not errors.
 *
 * An id is the same log in upper or lower case. It is handed on in lower case, and each answer
 * repeats it exactly as it was sent, so that the app finds the item in its outbox.
 */
final readonly class SyncLogsAction
{
    private const array ITEM_MEMBERS = ['id', 'smokedAt', 'productType', 'count', 'deleted'];
    private const array LOG_MEMBERS = ['smokedAt', 'productType', 'count'];

    public function __construct(
        private SyncLogs $syncLogs,
        private LoggingResponder $logging,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = AuthenticatedUser::id($request);
        $body = RequestBody::of($request);

        $fields = new Fields($body, ['items']);
        $items = [];
        $sent = [];
        $list = self::itemList($body, $fields);
        foreach ($list as $index => $raw) {
            $item = self::item($raw, "items.{$index}", $fields);
            if ($item !== null) {
                [$sent[], $items[]] = $item;
            }
        }
        if ($fields->hasErrors()) {
            throw $fields->failure();
        }

        $result = $this->syncLogs->execute($userId, $items);

        $answers = [];
        foreach ($result->items as $index => $answer) {
            $answers[] = $answer->rejection === null
                ? ['id' => $sent[$index], 'status' => 'accepted']
                : ['id' => $sent[$index], 'status' => 'rejected', 'reason' => $answer->rejection->value];
        }

        return $this->responder->json(['results' => $answers, 'days' => $this->logging->days($result->days)]);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<mixed> The items to read one by one. Empty when the list itself is wrong.
     */
    private static function itemList(array $body, Fields $fields): array
    {
        if (!array_key_exists('items', $body)) {
            $fields->reject('items', ErrorCode::Required, 'Is required.');

            return [];
        }
        $items = $body['items'];
        // JSON's [] and {} both arrive as an empty array: both hold no item.
        if (!is_array($items) || !array_is_list($items)) {
            $fields->reject('items', ErrorCode::WrongType, 'Must be a list.');

            return [];
        }
        if ($items === []) {
            $fields->reject('items', ErrorCode::TooShort, 'Must hold at least one item.');
        }
        if (count($items) > SyncLogs::MAX_ITEMS) {
            $fields->reject('items', ErrorCode::TooLong, 'Must hold at most ' . SyncLogs::MAX_ITEMS . ' items.');

            return [];
        }

        return $items;
    }

    /**
     * Reads one item. Its errors are noted in $fields under "items.<index>.<member>".
     *
     * @return array{string, LogItem}|null The id as it was sent, and the item. Null when it is malformed.
     */
    private static function item(mixed $raw, string $path, Fields $fields): ?array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $fields->reject($path, ErrorCode::WrongType, 'Must be an object.');

            return null;
        }

        $member = new Fields($raw, self::ITEM_MEMBERS);
        $id = $member->make('id', self::uuid(...));
        $item = array_key_exists('deleted', $raw) ? self::deletion($raw, $member, $id) : self::entry($member, $id);

        if ($member->hasErrors()) {
            foreach ($member->failure()->errors as $error) {
                $fields->reject("{$path}.{$error->field}", $error->code, $error->message);
            }

            return null;
        }

        return $id === null || $item === null ? null : [$id, $item];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function deletion(array $raw, Fields $member, ?string $id): ?LogItem
    {
        $deleted = $member->boolean('deleted');
        if ($deleted === false) {
            $member->reject('deleted', ErrorCode::InvalidValue, 'Must be true, or left out for a log.');
        } elseif ($deleted === true && array_intersect(self::LOG_MEMBERS, array_keys($raw)) !== []) {
            $member->reject('deleted', ErrorCode::Ambiguous, 'A deletion has no smokedAt, productType or count.');
        }

        return $id === null ? null : LogItem::deletion($id);
    }

    private static function entry(Fields $member, ?string $id): ?LogItem
    {
        $smokedAt = $member->make('smokedAt', self::instant(...));
        $productType = $member->enum('productType', ProductType::class);
        $count = $member->integer('count');
        if ($count !== null && ($count < 1 || $count > SmokeLog::MAX_COUNT)) {
            $member->reject('count', ErrorCode::OutOfRange, 'Must be a whole number from 1 to ' . SmokeLog::MAX_COUNT . '.');
        }
        if ($id === null || $smokedAt === null || $productType === null || $count === null || $member->hasErrors()) {
            return null;
        }

        return LogItem::entry($id, $smokedAt, $productType, $count);
    }

    /**
     * @throws InvalidValue
     */
    private static function uuid(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be a UUID.');
        }

        return $value;
    }

    /**
     * An RFC 3339 date-time with a UTC offset ("Z" or ±hh:mm) on an existing day. Fractions of a
     * second are allowed and dropped later.
     *
     * @throws InvalidValue
     */
    private static function instant(string $value): DateTimeImmutable
    {
        $invalid = new InvalidValue(ErrorCode::InvalidFormat, 'Must be a date and time as in RFC 3339 with a UTC offset, such as 2026-11-17T08:15:00Z.');
        $pattern = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|[+-](\d{2}):(\d{2}))\z/i';
        if (preg_match($pattern, $value, $parts) !== 1) {
            throw $invalid;
        }
        [, $year, $month, $day, $hour, $minute, $second, $offset] = $parts;
        if (!checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59
            || (int) ($parts[8] ?? 0) > 23 || (int) ($parts[9] ?? 0) > 59) {
            throw $invalid;
        }

        $zone = strtoupper($offset) === 'Z' ? '+00:00' : $offset;
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s P', "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second} {$zone}");
        if ($instant === false) {
            throw $invalid;
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
