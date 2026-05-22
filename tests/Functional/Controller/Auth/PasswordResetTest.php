<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Auth;

use App\Entity\PasswordResetToken;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PasswordResetTest extends FunctionalTestCase
{
    #[Test]
    public function password_reset_auto_verifies_unverified_user(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('unverified-reset@example.com');
        $this->assertFalse($user->isEmailVerified());

        $token = new PasswordResetToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
            ],
        ]);

        $this->assertResponseRedirects('/auth/login');

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertTrue($refreshed->isEmailVerified(), 'Completing a password reset should auto-verify the email');
    }

    #[Test]
    public function password_reset_does_not_re_verify_already_verified_user(): void
    {
        $client = $this->client;
        $user = $this->createVerifiedUser('already-verified@example.com');
        $verifiedAt = $user->getEmailVerifiedAt();

        $token = new PasswordResetToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
            ],
        ]);

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertTrue($refreshed->isEmailVerified());
        $this->assertSame(
            $verifiedAt->getTimestamp(),
            $refreshed->getEmailVerifiedAt()->getTimestamp(),
            'emailVerifiedAt should not change for an already-verified user'
        );
    }

    #[Test]
    public function password_reset_with_invalid_token_redirects_to_forgot_password(): void
    {
        $client = $this->client;

        $client->request('POST', '/auth/reset-password/notavalidtoken', [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
            ],
        ]);

        $this->assertResponseRedirects('/auth/forgot-password');
    }

    #[Test]
    public function password_reset_with_used_token_fails(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('usedtoken-reset@example.com');

        $token = new PasswordResetToken($user);
        $token->markUsed();
        $this->em->persist($token);
        $this->em->flush();

        $client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
            ],
        ]);

        $this->assertResponseRedirects('/auth/forgot-password');

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertFalse($refreshed->isEmailVerified(), 'A used token should not verify the email');
    }

    #[Test]
    public function user_can_login_after_resetting_password_on_unverified_account(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('fullflow@example.com');

        $token = new PasswordResetToken($user);
        $this->em->persist($token);
        $this->em->flush();

        // Complete the reset (also verifies email)
        $client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
            ],
        ]);
        $client->followRedirect(); // lands on /auth/login

        // Now login should succeed
        $client->request('POST', '/auth/login', [
            'email' => 'fullflow@example.com',
            'password' => 'NewPassword456!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertStringNotContainsString("hasn't been verified", (string) $client->getResponse()->getContent());
    }
}
