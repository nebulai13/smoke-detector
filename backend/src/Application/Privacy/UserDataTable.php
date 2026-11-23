<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use InvalidArgumentException;

/**
 * One table that holds data of a user, with what exports it and what erases it. A table cannot be
 * registered with only one of the two.
 */
final readonly class UserDataTable
{
    /** Members the export document has by itself (ExportAccount). */
    private const array RESERVED_MEMBERS = ['formatVersion', 'exportedAt', 'profile'];

    public function __construct(
        /** The name of the database table, as the schema has it. */
        public string $table,
        /** The member of the export document that holds this table's data, in camelCase. */
        public string $exportMember,
        public UserDataExporter $exporter,
        public UserDataEraser $eraser,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $table) !== 1) {
            throw new InvalidArgumentException('A table name is made of a-z, 0-9 and "_" and starts with a letter.');
        }
        if (preg_match('/\A[a-z][A-Za-z0-9]*\z/', $exportMember) !== 1) {
            throw new InvalidArgumentException('An export member is written in camelCase.');
        }
        if (in_array($exportMember, self::RESERVED_MEMBERS, true)) {
            throw new InvalidArgumentException("The export member \"{$exportMember}\" is reserved.");
        }
    }
}
