<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Legal;

use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Http\JsonResponder;

/**
 * GET /legal/consents (operation getConsentVersions): the versions of the three consent texts that
 * are in force (FR-ACC-02, LEG-03).
 *
 * A registration must name exactly these versions (RegisterUser). An app asks here before it shows
 * the form: when its own texts are older, it tells the user to update instead of letting the
 * registration fail at the end. The answer is the settings and nothing else, so it needs no
 * database and no use case.
 */
final readonly class ShowConsentVersionsAction
{
    public function __construct(
        private ConsentVersions $versions,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(): ResponseInterface
    {
        return $this->responder->json([
            'age18' => $this->versions->age18,
            'art9' => $this->versions->art9,
            'privacy' => $this->versions->privacy,
        ]);
    }
}
