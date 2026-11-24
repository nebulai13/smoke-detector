<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Nudge;

use SmokeDetector\Application\Friends\PublicProfile;

/**
 * Writes the nudges of today as the schema Nudges of api/openapi.yaml (HLD §4.14). A nudge has no
 * time and no text: only who sent it.
 */
final readonly class NudgesResponder
{
    /**
     * @param list<PublicProfile> $senders
     *
     * @return array{nudges: list<array{user: array<string, string>}>}
     */
    public function nudges(array $senders): array
    {
        return [
            'nudges' => array_map(
                static fn (PublicProfile $sender): array => [
                    'user' => ['userId' => $sender->userId->value, 'displayName' => $sender->displayName->value, 'avatar' => $sender->avatar->key],
                ],
                $senders,
            ),
        ];
    }
}
