<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Auth;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class AccountLockoutTest extends FunctionalTestCase
{
    #[Test]
    public function wrongPasswordIncrementsFailedLoginCount(): void
    {
        $user = $this->createVerifiedUser('lockcount@example.com');

        $this->client->request('POST', '/auth/login', [
            'email' => 'lockcount@example.com',
            'password' => 'WrongPassword!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(1, $refreshed->getFailedLoginCount());
    }

    #[Test]
    public function accountIsLockedAfterFiveFailedAttempts(): void
    {
        $this->createVerifiedUser('fivefails@example.com');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/auth/login', [
                'email' => 'fivefails@example.com',
                'password' => 'WrongPassword!',
                '_csrf_token' => 'ignored-in-test',
            ]);
        }

        $refreshed = $this->em->getRepository(User::class)->findOneBy(['email' => 'fivefails@example.com']);
        $this->assertNotNull($refreshed, 'User must be findable after requests');
        $this->assertTrue($refreshed->isLocked(), 'Account should be locked after 5 failed attempts');
        $this->assertSame(5, $refreshed->getFailedLoginCount());
    }

    #[Test]
    public function lockedAccountLoginShowsLockoutError(): void
    {
        $user = $this->createVerifiedUser('locked@example.com');
        $user->lockUntil(new DateTimeImmutable('+15 minutes'));
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'locked@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->client->followRedirect();
        $this->assertStringContainsString('temporarily locked', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function successfulLoginResetsFailedLoginCount(): void
    {
        $user = $this->createVerifiedUser('resetcount@example.com');
        $user->incrementFailedLoginCount();
        $user->incrementFailedLoginCount();
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'resetcount@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(0, $refreshed->getFailedLoginCount(), 'Successful login should reset the failed login count');
        $this->assertFalse($refreshed->isLocked());
    }

    #[Test]
    public function passwordResetUnlocksLockedAccount(): void
    {
        $user = $this->createVerifiedUser('resetlocked@example.com');
        $user->lockUntil(new DateTimeImmutable('+15 minutes'));
        for ($i = 0; $i < 5; ++$i) {
            $user->incrementFailedLoginCount();
        }
        $this->em->flush();

        $this->assertTrue($user->isLocked());

        $token = new PasswordResetToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
                '_token' => 'test-token',
            ],
        ]);
        $this->assertResponseRedirects('/auth/login');

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertFalse($refreshed->isLocked(), 'Password reset should unlock a locked account');
        $this->assertSame(0, $refreshed->getFailedLoginCount(), 'Password reset should reset the failed login count');
    }

    #[Test]
    public function lockedUserCanLoginAfterPasswordReset(): void
    {
        $user = $this->createVerifiedUser('unlocklogin@example.com');
        $user->lockUntil(new DateTimeImmutable('+15 minutes'));
        $this->em->flush();

        $token = new PasswordResetToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('POST', '/auth/reset-password/' . $token->getToken(), [
            'reset_password_form' => [
                'plainPassword' => ['first' => 'NewPassword456!', 'second' => 'NewPassword456!'],
                '_token' => 'test-token',
            ],
        ]);

        $this->client->request('POST', '/auth/login', [
            'email' => 'unlocklogin@example.com',
            'password' => 'NewPassword456!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertStringNotContainsString('temporarily locked', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function unverifiedLoginAttemptsDoNotIncrementLockoutCounter(): void
    {
        $this->createUnverifiedUser('nolockout@example.com');

        for ($i = 0; $i < 10; ++$i) {
            $this->client->request('POST', '/auth/login', [
                'email' => 'nolockout@example.com',
                'password' => 'Password123!',
                '_csrf_token' => 'ignored-in-test',
            ]);
        }

        $refreshed = $this->em->getRepository(User::class)->findOneBy(['email' => 'nolockout@example.com']);
        $this->assertNotNull($refreshed, 'User must be findable after requests');
        $this->assertFalse($refreshed->isLocked(), 'Unverified users should never be locked out by login attempts');
        $this->assertSame(0, $refreshed->getFailedLoginCount());
    }
}
