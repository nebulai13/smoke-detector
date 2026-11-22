<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use SmokeDetector\Application\Account\UserId;

/**
 * Reads what one table holds about a user, for the data export (FR-ACC-07).
 */
interface UserDataExporter
{
    /**
     * @return array<array-key, mixed>|null Ready to be written as JSON, with the member names of the
     *                                      schema in api/openapi.yaml: a list of entries (empty when
     *                                      there are none) for a table with many rows per user, one
     *                                      object for a table with one row, or null when that row
     *                                      does not exist. Never a secret: no password hash, no
     *                                      token, no token hash.
     */
    public function export(UserId $userId): ?array;
}
