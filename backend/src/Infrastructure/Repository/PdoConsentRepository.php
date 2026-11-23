<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentRepository;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The consent records in the `consents` table.
 */
final readonly class PdoConsentRepository implements ConsentRepository
{
    public function __construct(private Sql $sql)
    {
    }

    public function replaceAll(UserId $userId, array $consents): void
    {
        $this->sql->execute('DELETE FROM consents WHERE user_id = ?', [$userId->value]);
        $this->add($userId, $consents);
    }

    public function add(UserId $userId, array $consents): void
    {
        // Plain inserts, and no DELETE before them: a DELETE that finds nothing still locks the gap
        // these rows go into, and two new registrations at the same moment would then wait for each
        // other until the database gives one of them up (error 1213).
        foreach ($consents as $consent) {
            $this->sql->execute(
                'INSERT INTO consents (user_id, type, version, granted_at) VALUES (?, ?, ?, ?)',
                [$userId->value, $consent->type->value, $consent->version, Row::sqlTime($consent->grantedAt)],
            );
        }
    }

    public function ofUser(UserId $userId): array
    {
        $consents = [];
        $rows = $this->sql->rows(
            'SELECT type, version, granted_at FROM consents WHERE user_id = ? ORDER BY type, version',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $consents[] = new Consent(ConsentType::from($row->string('type')), $row->string('version'), $row->time('granted_at'));
        }

        return $consents;
    }
}
