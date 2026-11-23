<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Auth\GivenConsent;
use SmokeDetector\Application\Auth\PasswordPolicy;
use SmokeDetector\Application\Auth\RegisterUser;
use SmokeDetector\Application\Auth\Registration;
use SmokeDetector\Http\ClientAddress;
use SmokeDetector\Http\Input\Fields;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\RequestBody;

/**
 * POST /auth/register (operation register): always 202 for a valid request, whatever the state of
 * the email address (FR-ACC-01). Each of the three consents comes with the version of the text the
 * app showed, and counts only for the version in force (FR-ACC-02, LEG-03).
 */
final readonly class RegisterAction
{
    public function __construct(
        private RegisterUser $registerUser,
        private PasswordPolicy $passwordPolicy,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $fields = new Fields(RequestBody::of($request), [
            'email', 'password', 'displayName', 'avatar', 'locale', 'timeZone',
            'consents.age18.accepted', 'consents.age18.version',
            'consents.art9.accepted', 'consents.art9.version',
            'consents.privacy.accepted', 'consents.privacy.version',
        ]);

        $email = $fields->make('email', EmailAddress::fromInput(...));
        $password = $fields->string('password');
        $displayName = $fields->make('displayName', DisplayName::fromInput(...));
        $avatar = $fields->make('avatar', Avatar::fromInput(...));
        $locale = $fields->make('locale', Locale::fromInput(...));
        $timeZone = $fields->make('timeZone', TimeZone::fromInput(...));
        $age18 = self::consent($fields, 'age18');
        $art9 = self::consent($fields, 'art9');
        $privacy = self::consent($fields, 'privacy');

        // The use case enforces these rules as well. Checked here too, so that one answer lists
        // everything that is wrong with the form.
        if ($password !== null) {
            $fields->rejectIf('password', $this->passwordPolicy->violation($password));
        }
        foreach ([[ConsentType::Age18, $age18], [ConsentType::Art9, $art9], [ConsentType::Privacy, $privacy]] as [$type, $consent]) {
            foreach ($consent === null ? [] : $this->registerUser->consentViolations($type, $consent) as $violation) {
                $fields->reject($violation->field, $violation->code, $violation->message);
            }
        }

        if (
            $email === null || $password === null || $displayName === null || $avatar === null || $locale === null
            || $timeZone === null || $age18 === null || $art9 === null || $privacy === null || $fields->hasErrors()
        ) {
            throw $fields->failure();
        }

        $this->registerUser->execute(
            new Registration($email, $password, $displayName, $avatar, $locale, $timeZone, $age18, $art9, $privacy),
            ClientAddress::of($request),
        );

        return $this->responder->json(['status' => 'verification_sent'], 202);
    }

    /**
     * One member of "consents": whether the user agreed, and the version of the text the app showed.
     *
     * @return GivenConsent|null Null when a member of it is missing or of the wrong type (and noted).
     */
    private static function consent(Fields $fields, string $name): ?GivenConsent
    {
        $accepted = $fields->boolean("consents.{$name}.accepted");
        $version = $fields->string("consents.{$name}.version");

        return $accepted === null || $version === null ? null : new GivenConsent($accepted, $version);
    }
}
