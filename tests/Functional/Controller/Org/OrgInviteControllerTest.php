<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Org;

use App\Entity\OrgInvitation;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

final class OrgInviteControllerTest extends FunctionalTestCase
{
    #[Test]
    public function invitePageRequiresPermission(): void
    {
        $owner = $this->createUserWithOrg('invite-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $member = $this->createVerifiedUser('invite-member@example.com');
        $memberRole = static::getContainer()->get(\App\Repository\RoleRepository::class)->findBySlug('org-member');
        $this->assertNotNull($memberRole);
        $this->em->persist(new \App\Entity\UserRole($member, $memberRole, $org->getId()));
        $member->setOrganization($org);
        $this->em->flush();

        $this->client->loginUser($member);
        $this->client->request('GET', '/org/invite');

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function ownerSeesInviteForm(): void
    {
        $owner = $this->createUserWithOrg('invite-form@example.com', 'Password123!', 'Owner');
        $this->client->loginUser($owner);
        $this->client->request('GET', '/org/invite');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="org_invite[email]"]');
    }

    #[Test]
    public function ownerCanSendInvitation(): void
    {
        $owner = $this->createUserWithOrg('invite-send@example.com', 'Password123!', 'Owner');
        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/invite', [
            'org_invite' => ['email' => 'newperson@example.com', '_token' => $this->getCsrfToken('org_invite')],
        ]);

        $this->assertResponseRedirects('/org/settings');

        $invitation = $this->em->getRepository(OrgInvitation::class)->findOneBy(['email' => 'newperson@example.com']);
        $this->assertNotNull($invitation);
        $this->assertTrue($invitation->isPending());
    }

    #[Test]
    public function cannotInviteExistingUser(): void
    {
        $owner = $this->createUserWithOrg('invite-existing-owner@example.com', 'Password123!', 'Owner');
        $this->createVerifiedUser('existing@example.com');

        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/invite', [
            'org_invite' => ['email' => 'existing@example.com', '_token' => $this->getCsrfToken('org_invite')],
        ]);

        $this->assertResponseStatusCodeSame(422);

        // No invitation should have been created
        $invitation = $this->em->getRepository(OrgInvitation::class)->findOneBy(['email' => 'existing@example.com']);
        $this->assertNull($invitation);
    }

    #[Test]
    public function cannotSendDuplicateInvitation(): void
    {
        $owner = $this->createUserWithOrg('invite-dup@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $existing = new OrgInvitation($org, 'pending@example.com', $owner);
        $this->em->persist($existing);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/invite', [
            'org_invite' => ['email' => 'pending@example.com', '_token' => $this->getCsrfToken('org_invite')],
        ]);

        $this->assertResponseStatusCodeSame(422);

        // Only the original invitation should exist (no duplicate)
        $invitations = $this->em->getRepository(OrgInvitation::class)->findBy(['email' => 'pending@example.com']);
        $this->assertCount(1, $invitations);
    }

    #[Test]
    public function registerWithValidInviteJoinsOrg(): void
    {
        $owner = $this->createUserWithOrg('invite-reg-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $invitation = new OrgInvitation($org, 'invited@example.com', $owner);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->client->request('GET', '/auth/register?invite=' . $invitation->getToken());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $org->getName());

        $this->client->request('POST', '/auth/register?invite=' . $invitation->getToken(), [
            'registration_form' => [
                'name' => 'Invited Person',
                'email' => 'invited@example.com',
                'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                'agreeTerms' => '1',
                '_token' => $this->getCsrfToken('registration_form'),
            ],
        ]);

        $this->assertResponseRedirects('/auth/login');

        $this->em->clear();
        $newUser = $this->em->getRepository(\App\Entity\User::class)->findByEmail('invited@example.com');
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->isEmailVerified());
        $this->assertNotNull($newUser->getOrganization());
        $this->assertTrue($newUser->getOrganization()->getId()->equals($org->getId()));

        $refreshedInvitation = $this->em->find(OrgInvitation::class, $invitation->getId());
        $this->assertNotNull($refreshedInvitation);
        $this->assertTrue($refreshedInvitation->isAccepted());
    }

    #[Test]
    public function registerWithExpiredInviteDoesNotJoinOrg(): void
    {
        $owner = $this->createUserWithOrg('invite-expired-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $invitation = new OrgInvitation($org, 'expired@example.com', $owner);
        // Expire it by setting expiresAt in the past via reflection
        $ref = new ReflectionProperty(OrgInvitation::class, 'expiresAt');
        $ref->setAccessible(true);
        $ref->setValue($invitation, new DateTimeImmutable('-1 hour'));
        $this->em->persist($invitation);
        $this->em->flush();

        $this->client->request('POST', '/auth/register?invite=' . $invitation->getToken(), [
            'registration_form' => [
                'name' => 'Expired Person',
                'email' => 'expired@example.com',
                'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                'agreeTerms' => '1',
                '_token' => $this->getCsrfToken('registration_form'),
            ],
        ]);

        $this->assertResponseRedirects('/auth/verify-email-notice');

        $this->em->clear();
        $newUser = $this->em->getRepository(\App\Entity\User::class)->findByEmail('expired@example.com');
        $this->assertNotNull($newUser);
        $this->assertNull($newUser->getOrganization());
    }

    #[Test]
    public function ownerCanRevokeInvitation(): void
    {
        $owner = $this->createUserWithOrg('revoke-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $invitation = new OrgInvitation($org, 'revoke-me@example.com', $owner);
        $this->em->persist($invitation);
        $this->em->flush();
        $invId = $invitation->getId()->toRfc4122();

        $this->client->loginUser($owner);
        $this->client->request('POST', '/org/invitations/' . $invId . '/revoke', [
            '_token' => $this->getCsrfToken('org_invitation_revoke_' . $invId),
        ]);

        $this->assertResponseRedirects('/org/settings');

        $this->em->clear();
        $revoked = $this->em->find(OrgInvitation::class, $invitation->getId());
        $this->assertNull($revoked);
    }

    #[Test]
    public function memberWithoutPermissionCannotRevoke(): void
    {
        $owner = $this->createUserWithOrg('revoke-perm-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $member = $this->createVerifiedUser('revoke-perm-member@example.com');
        $memberRole = static::getContainer()->get(\App\Repository\RoleRepository::class)->findBySlug('org-member');
        $this->assertNotNull($memberRole);
        $this->em->persist(new \App\Entity\UserRole($member, $memberRole, $org->getId()));
        $member->setOrganization($org);

        $invitation = new OrgInvitation($org, 'someone@example.com', $owner);
        $this->em->persist($invitation);
        $this->em->flush();
        $invId = $invitation->getId()->toRfc4122();

        $this->client->loginUser($member);
        $this->client->request('POST', '/org/invitations/' . $invId . '/revoke', [
            '_token' => $this->getCsrfToken('org_invitation_revoke_' . $invId),
        ]);

        $this->assertResponseStatusCodeSame(403);

        // Invitation should still exist
        $this->em->clear();
        $still = $this->em->find(OrgInvitation::class, $invitation->getId());
        $this->assertNotNull($still);
    }

    #[Test]
    public function pendingInvitationsAppearOnSettingsPage(): void
    {
        $owner = $this->createUserWithOrg('pending-list-owner@example.com', 'Password123!', 'Owner');
        $org = $owner->getOrganization();
        $this->assertNotNull($org);

        $invitation = new OrgInvitation($org, 'listed@example.com', $owner);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/org/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'listed@example.com');
        $this->assertSelectorTextContains('body', 'Pending invitations');
    }

    private function getCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
