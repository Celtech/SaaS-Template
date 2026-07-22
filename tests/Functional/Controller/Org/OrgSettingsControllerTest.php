<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Org;

use App\Entity\UserRole;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRoleRepository;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrgSettingsControllerTest extends FunctionalTestCase
{
    #[Test]
    public function settingsPageShowsOrgNameAndMembers(): void
    {
        $user = $this->createUserWithOrg('settings-view@example.com', 'Password123!', 'Alice');
        $this->client->loginUser($user);
        $this->client->request('GET', '/org/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="org_settings[name]"]');
        $this->assertSelectorTextContains('body', 'Alice');
    }

    #[Test]
    public function ownerCanUpdateOrgName(): void
    {
        $user = $this->createUserWithOrg('settings-update@example.com', 'Password123!', 'Bob');
        $this->client->loginUser($user);
        $this->client->request('POST', '/org/settings', [
            'org_settings' => ['name' => 'New Name Inc.', '_token' => 'test'],
        ]);

        $this->assertResponseRedirects('/org/settings');
        $this->em->clear();

        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->getOrganization());
        $this->assertSame('New Name Inc.', $refreshed->getOrganization()->getName());

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findByAction('org.settings.updated');
        $this->assertNotEmpty($entries, 'Expected an org.settings.updated audit log entry.');
    }

    #[Test]
    public function emptyOrgNameShowsValidationError(): void
    {
        $user = $this->createUserWithOrg('settings-empty@example.com');
        $this->client->loginUser($user);
        $this->client->request('POST', '/org/settings', [
            'org_settings' => ['name' => '', '_token' => 'test'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function memberWithoutPermissionCannotUpdateName(): void
    {
        $owner = $this->createUserWithOrg('org-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();

        $this->assertNotNull($org);
        $member = $this->createVerifiedUser('org-member@example.com');
        $memberRole = static::getContainer()->get(RoleRepository::class)->findBySlug('org-member');
        $this->assertNotNull($memberRole);
        $this->em->persist(new UserRole($member, $memberRole, $org->getId()));
        $member->setOrganization($org);
        $this->em->flush();

        $this->client->loginUser($member);
        $this->client->request('POST', '/org/settings', [
            'org_settings' => ['name' => 'Hacked Name', '_token' => 'test'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function ownerCanRemoveMember(): void
    {
        $owner = $this->createUserWithOrg('remove-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $member = $this->createVerifiedUser('remove-member@example.com');
        $memberRole = static::getContainer()->get(RoleRepository::class)->findBySlug('org-member');
        $this->assertNotNull($memberRole);
        $this->em->persist(new UserRole($member, $memberRole, $org->getId()));
        $member->setOrganization($org);
        $this->em->flush();

        $memberId = $member->getId()->toRfc4122();
        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/members/' . $memberId . '/remove', [
            '_token' => $this->generateCsrfToken('org_member_remove_' . $memberId),
        ]);

        $this->assertResponseRedirects('/org/settings');
        $this->em->clear();

        $refreshed = $this->em->find(\App\Entity\User::class, $member->getId());
        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->getOrganization());

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findBySubject($memberId, 'user');
        $actions = array_map(static fn ($e) => $e->getAction(), $entries);
        $this->assertContains('org.member.removed', $actions);
    }

    #[Test]
    public function cannotRemoveOrgOwner(): void
    {
        $owner = $this->createUserWithOrg('cant-remove-owner@example.com', 'Password123!', 'Owner');
        $ownerId = $owner->getId()->toRfc4122();
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/members/' . $ownerId . '/remove', [
            '_token' => $this->generateCsrfToken('org_member_remove_' . $ownerId),
        ]);

        $this->assertResponseRedirects('/org/settings');

        // Owner must still belong to the org
        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $owner->getId());
        $this->assertNotNull($refreshed);
        $refreshedOrg = $refreshed->getOrganization();
        $this->assertNotNull($refreshedOrg);
        $this->assertTrue($refreshedOrg->getId()->equals($org->getId()));
    }

    #[Test]
    public function ownerCanChangeMemberRole(): void
    {
        $owner = $this->createUserWithOrg('role-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $member = $this->createVerifiedUser('role-member@example.com');
        $memberRole = static::getContainer()->get(RoleRepository::class)->findBySlug('org-member');
        $this->assertNotNull($memberRole);
        $this->em->persist(new UserRole($member, $memberRole, $org->getId()));
        $member->setOrganization($org);
        $this->em->flush();

        $memberId = $member->getId()->toRfc4122();
        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/members/' . $memberId . '/role', [
            '_token' => $this->generateCsrfToken('org_member_role_' . $memberId),
            'role' => 'org-admin',
        ]);

        $this->assertResponseRedirects('/org/settings');

        $userRoleRepo = static::getContainer()->get(UserRoleRepository::class);
        $this->em->clear();
        $refreshedMember = $this->em->find(\App\Entity\User::class, $member->getId());
        $this->assertNotNull($refreshedMember);
        $roles = $userRoleRepo->findForUser($refreshedMember, $org->getId());
        $slugs = array_map(static fn (UserRole $ur) => $ur->getRole()->getSlug(), $roles);
        $this->assertContains('org-admin', $slugs);
        $this->assertNotContains('org-member', $slugs);

        $auditLogRepo = static::getContainer()->get(AuditLogRepository::class);
        $entries = $auditLogRepo->findBySubject($memberId, 'user');
        $actions = array_map(static fn ($e) => $e->getAction(), $entries);
        $this->assertContains('org.member.role_changed', $actions);
    }

    private function generateCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
