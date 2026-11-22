<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

/**
 * The consent records (HLD §4.3 "consents").
 */
interface ConsentRepository
{
    /**
     * Stores the consents of an account that has none yet: a new registration. Nothing is looked
     * for or deleted first, so two registrations at the same moment do not lock each other out.
     *
     * @param list<Consent> $consents
     */
    public function add(UserId $userId, array $consents): void;

    /**
     * Stores these consents as the only ones of the user: a registration that is submitted again
     * replaces the consents of the earlier submission.
     *
     * @param list<Consent> $consents
     */
    public function replaceAll(UserId $userId, array $consents): void;

    /**
     * @return list<Consent> Ordered by type.
     */
    public function ofUser(UserId $userId): array;
}
