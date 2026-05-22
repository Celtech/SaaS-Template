<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Auth;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class InactiveUserLoginTest extends FunctionalTestCase
{
    #[Test]
    public function deletedUserCannotLogin(): void
    {
        $user = $this->createVerifiedUser('deleted@example.com');
        $user->softDelete();
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'deleted@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Your account is not active.', $content);
    }

    #[Test]
    public function suspendedUserCannotLogin(): void
    {
        $user = $this->createVerifiedUser('suspended@example.com');
        $user->suspend();
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Your account is not active.', $content);
    }

    #[Test]
    public function unsuspendedUserCanLoginAgain(): void
    {
        $user = $this->createVerifiedUser('unsuspend@example.com');
        $user->suspend();
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'unsuspend@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);
        $this->client->followRedirect();
        $this->assertStringContainsString('Your account is not active.', (string) $this->client->getResponse()->getContent());

        $user->unsuspend();
        $this->em->flush();

        $this->client->request('POST', '/auth/login', [
            'email' => 'unsuspend@example.com',
            'password' => 'Password123!',
            '_csrf_token' => 'ignored-in-test',
        ]);
        $this->assertResponseRedirects();
    }

    #[Test]
    public function deletedUserReceivesNoHintAboutAccountExistence(): void
    {
        $user = $this->createVerifiedUser('ghost@example.com');
        $user->softDelete();
        $this->em->flush();

        // Wrong password on a deleted account should show the same inactive message,
        // not "Invalid credentials" (avoids confirming the account ever existed)
        $this->client->request('POST', '/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'WrongPassword!',
            '_csrf_token' => 'ignored-in-test',
        ]);

        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Your account is not active.', $content);
        $this->assertStringNotContainsString('Invalid credentials', $content);
    }
}
