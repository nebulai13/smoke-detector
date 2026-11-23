<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use SmokeDetector\Application\Auth\InvalidAccessToken;

/**
 * Who a request with an access token is (HLD §4.5 "Login and tokens", NFR-05, FR-ACC-08).
 *
 * The token itself is checked without the database. Whether its account still exists is not in
 * the token, so that is looked up: a deleted account's token must stop working at once.
 */
final class AuthenticateTest extends AuthUseCaseTestCase
{
    public function test_NFR_05_the_access_token_of_an_existing_account_names_its_user(): void
    {
        $user = $this->givenUser();
        $token = $this->accessTokens->issue($user->id, 0)->token;

        self::assertTrue($user->id->equals($this->authenticate()->execute($token)));
    }

    public function test_NFR_05_a_token_the_verifier_refuses_is_refused(): void
    {
        $this->givenUser();

        $this->expectException(InvalidAccessToken::class);

        $this->authenticate()->execute('not-an-access-token');
    }

    public function test_FR_ACC_08_the_access_token_of_a_deleted_account_is_refused(): void
    {
        $user = $this->givenUser();
        $token = $this->accessTokens->issue($user->id, 0)->token;
        $this->users->delete($user->id);

        $this->expectException(InvalidAccessToken::class);

        $this->authenticate()->execute($token);
    }

    public function test_FR_ACC_03_a_token_for_an_unconfirmed_account_is_refused(): void
    {
        // No such token is ever issued (login refuses). Should one exist all the same, it opens nothing.
        $user = $this->givenUser(verified: false);
        $token = $this->accessTokens->issue($user->id, 0)->token;

        $this->expectException(InvalidAccessToken::class);

        $this->authenticate()->execute($token);
    }

    public function test_FR_ACC_05_an_access_token_from_before_a_password_reset_is_refused(): void
    {
        // "Signs the user out on all devices" includes the access tokens that are still out: they
        // carry the version of the account's sessions they were issued for, and a reset raises it.
        $user = $this->givenUser();
        $old = $this->accessTokens->issue($user->id, $user->tokenVersion)->token;
        $this->users->update($user->withNextTokenVersion());
        $new = $this->accessTokens->issue($user->id, 1)->token;

        self::assertTrue($user->id->equals($this->authenticate()->execute($new)));
        $this->expectException(InvalidAccessToken::class);
        $this->authenticate()->execute($old);
    }

    public function test_FR_ACC_05_an_access_token_of_a_later_version_than_the_account_is_refused_too(): void
    {
        // Only the exact version counts. A token "from the future" is not ours.
        $user = $this->givenUser();

        $this->expectException(InvalidAccessToken::class);

        $this->authenticate()->execute($this->accessTokens->issue($user->id, 1)->token);
    }
}
