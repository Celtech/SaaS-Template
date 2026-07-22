<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Profile;

use App\Repository\AuditLogRepository;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProfileControllerTest extends FunctionalTestCase
{
    #[Test]
    public function changingPasswordWritesAnAuditLogEntry(): void
    {
        $user = $this->createUserWithOrg('profile-change-password@example.com', 'OldPassword123!');

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/change-password', [
            'change_password_form' => [
                'currentPassword' => 'OldPassword123!',
                'newPassword' => ['first' => 'NewPassword123!', 'second' => 'NewPassword123!'],
                '_token' => $this->csrfToken('change_password_form'),
            ],
        ]);

        $this->assertResponseRedirects('/profile/security');

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findByActor($user->getId()->toRfc4122());
        $actions = array_map(static fn ($e) => $e->getAction(), $entries);
        $this->assertContains('security.password.changed', $actions);
    }

    #[Test]
    public function wrongCurrentPasswordDoesNotChangePasswordOrLogAnything(): void
    {
        $user = $this->createUserWithOrg('profile-wrong-password@example.com', 'OldPassword123!');

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/change-password', [
            'change_password_form' => [
                'currentPassword' => 'WrongPassword!',
                'newPassword' => ['first' => 'NewPassword123!', 'second' => 'NewPassword123!'],
                '_token' => $this->csrfToken('change_password_form'),
            ],
        ]);

        $this->assertResponseRedirects('/profile/security');

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findByActor($user->getId()->toRfc4122());
        $actions = array_map(static fn ($e) => $e->getAction(), $entries);
        $this->assertNotContains('security.password.changed', $actions);
    }

    #[Test]
    public function changingEmailWritesAnAuditLogEntryWithOldAndNewEmail(): void
    {
        $user = $this->createUserWithOrg('profile-change-email-old@example.com');

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/update', [
            'profile_form' => [
                'name' => $user->getName(),
                'email' => 'profile-change-email-new@example.com',
                '_token' => $this->csrfToken('profile_form'),
            ],
        ]);

        $this->assertResponseRedirects('/profile');

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findByActor($user->getId()->toRfc4122());
        $emailChanged = null;
        foreach ($entries as $entry) {
            if ($entry->getAction() === 'security.email.changed') {
                $emailChanged = $entry;
            }
        }

        $this->assertNotNull($emailChanged, 'Expected a security.email.changed audit log entry.');
        $this->assertSame('profile-change-email-old@example.com', $emailChanged->getNewValue()['old_email'] ?? null);
        $this->assertSame('profile-change-email-new@example.com', $emailChanged->getNewValue()['new_email'] ?? null);
    }

    private function csrfToken(string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }
}
