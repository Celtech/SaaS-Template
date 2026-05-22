<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Auth;

use App\Entity\EmailVerificationToken;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class EmailVerificationTest extends FunctionalTestCase
{
    #[Test]
    public function registration_redirects_to_verify_email_notice(): void
    {
        $client = $this->client;

        $client->request('POST', '/auth/register', [
            'registration_form' => [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                'agreeTerms' => '1',
                '_token' => 'test-token',
            ],
        ]);

        $this->assertResponseRedirects('/auth/verify-email-notice');
    }

    #[Test]
    public function registration_creates_email_verification_token(): void
    {
        $client = $this->client;

        $client->request('POST', '/auth/register', [
            'registration_form' => [
                'name' => 'New User',
                'email' => 'tokencheck@example.com',
                'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                'agreeTerms' => '1',
                '_token' => 'test-token',
            ],
        ]);

        $this->em->clear();
        $user = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'tokencheck@example.com']);
        $this->assertNotNull($user);

        $token = $this->em->getRepository(EmailVerificationToken::class)->findLatestActiveForUser($user);
        $this->assertNotNull($token, 'A verification token should exist after registration');
        $this->assertFalse($token->isUsed());
        $this->assertFalse($user->isEmailVerified());
    }

    #[Test]
    public function login_is_blocked_for_unverified_user(): void
    {
        $client = $this->client;
        $this->createUnverifiedUser('blocked@example.com');

        $client->request('POST', '/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $client->followRedirect();
        $this->assertStringContainsString(
            "hasn't been verified",
            (string) $client->getResponse()->getContent()
        );
    }

    #[Test]
    public function login_with_unverified_email_does_not_increment_lockout_counter(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('lockout@example.com');

        $client->request('POST', '/auth/login', [
            'email' => 'lockout@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertSame(0, $refreshed->getFailedLoginCount(), 'Unverified login should not count as a lockout failure');
    }

    #[Test]
    public function verify_email_with_valid_token_marks_user_as_verified(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('verify@example.com');

        $token = new EmailVerificationToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $client->request('GET', '/auth/verify-email/' . $token->getToken());
        $this->assertResponseRedirects('/auth/login');

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertTrue($refreshed->isEmailVerified());
    }

    #[Test]
    public function verify_email_with_invalid_token_redirects_with_error(): void
    {
        $client = $this->client;

        $client->request('GET', '/auth/verify-email/thisisnotavalidtoken');
        $this->assertResponseRedirects('/auth/login');

        $client->followRedirect();
        $this->assertStringContainsString('invalid or has expired', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function verify_email_with_already_used_token_redirects_with_error(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('usedtoken@example.com');

        $token = new EmailVerificationToken($user);
        $token->markUsed();
        $this->em->persist($token);
        $this->em->flush();

        $client->request('GET', '/auth/verify-email/' . $token->getToken());
        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function resend_within_cooldown_does_not_send_new_token(): void
    {
        $client = $this->client;
        $user = $this->createUnverifiedUser('ratelimit@example.com');

        // Create a token that was just issued (within 60s cooldown)
        $token = new EmailVerificationToken($user);
        $this->em->persist($token);
        $this->em->flush();

        $tokenCountBefore = count($this->em->getRepository(EmailVerificationToken::class)->findBy(['user' => $user]));

        $client->request('POST', '/auth/resend-verification', [
            'email' => 'ratelimit@example.com',
            '_token' => 'ignored-in-test',
        ]);

        $this->em->clear();
        $tokenCountAfter = count($this->em->getRepository(EmailVerificationToken::class)->findBy(['user' => $user]));
        $this->assertSame($tokenCountBefore, $tokenCountAfter, 'No new token should be created within the cooldown period');
    }

    #[Test]
    public function login_succeeds_after_email_is_verified(): void
    {
        $client = $this->client;
        $this->createVerifiedUser('success@example.com');

        $client->request('POST', '/auth/login', [
            'email' => 'success@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertStringNotContainsString("hasn't been verified", (string) $client->getResponse()->getContent());
    }
}
