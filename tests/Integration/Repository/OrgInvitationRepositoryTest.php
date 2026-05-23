<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Organization;
use App\Entity\OrgInvitation;
use App\Entity\User;
use App\Repository\OrgInvitationRepository;
use App\Tests\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

final class OrgInvitationRepositoryTest extends IntegrationTestCase
{
    private OrgInvitationRepository $repo;
    private EntityManagerInterface $em;
    private User $owner;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = static::getContainer()->get(OrgInvitationRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->owner = new User('owner@example.com', 'Owner');
        $this->owner->setPassword('hashed');
        $this->owner->markEmailVerified();
        $this->em->persist($this->owner);

        $this->org = new Organization('Acme', $this->owner);
        $this->owner->setOrganization($this->org);
        $this->em->persist($this->org);
        $this->em->flush();
    }

    #[Test]
    public function findPendingByTokenReturnsPendingInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'invited@example.com', $this->owner);
        $this->em->persist($invitation);
        $this->em->flush();

        $found = $this->repo->findPendingByToken($invitation->getToken());

        $this->assertNotNull($found);
        $this->assertSame($invitation->getToken(), $found->getToken());
    }

    #[Test]
    public function findPendingByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->repo->findPendingByToken('nonexistent_token_that_does_not_exist_at_all'));
    }

    #[Test]
    public function findPendingByTokenReturnsNullForAcceptedInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'accepted@example.com', $this->owner);
        $invitation->accept();
        $this->em->persist($invitation);
        $this->em->flush();

        $this->assertNull($this->repo->findPendingByToken($invitation->getToken()));
    }

    #[Test]
    public function findPendingByTokenReturnsNullForExpiredInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'expired@example.com', $this->owner);
        $this->setExpiresAt($invitation, new DateTimeImmutable('-1 hour'));
        $this->em->persist($invitation);
        $this->em->flush();

        $this->assertNull($this->repo->findPendingByToken($invitation->getToken()));
    }

    #[Test]
    public function findPendingByEmailReturnsPendingInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'member@example.com', $this->owner);
        $this->em->persist($invitation);
        $this->em->flush();

        $found = $this->repo->findPendingByEmail('member@example.com');

        $this->assertNotNull($found);
        $this->assertSame('member@example.com', $found->getEmail());
    }

    #[Test]
    public function findPendingByEmailIsCaseInsensitive(): void
    {
        $invitation = new OrgInvitation($this->org, 'member@example.com', $this->owner);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->assertNotNull($this->repo->findPendingByEmail('MEMBER@EXAMPLE.COM'));
    }

    #[Test]
    public function findPendingByEmailReturnsNullForExpiredInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'expired@example.com', $this->owner);
        $this->setExpiresAt($invitation, new DateTimeImmutable('-1 hour'));
        $this->em->persist($invitation);
        $this->em->flush();

        $this->assertNull($this->repo->findPendingByEmail('expired@example.com'));
    }

    #[Test]
    public function findPendingByEmailReturnsNullForAcceptedInvitation(): void
    {
        $invitation = new OrgInvitation($this->org, 'done@example.com', $this->owner);
        $invitation->accept();
        $this->em->persist($invitation);
        $this->em->flush();

        $this->assertNull($this->repo->findPendingByEmail('done@example.com'));
    }

    #[Test]
    public function findPendingByOrgReturnsOnlyPendingForThatOrg(): void
    {
        $owner2 = new User('owner2@example.com', 'Owner 2');
        $owner2->setPassword('hashed');
        $owner2->markEmailVerified();
        $this->em->persist($owner2);
        $org2 = new Organization('Beta', $owner2);
        $owner2->setOrganization($org2);
        $this->em->persist($org2);

        $pending = new OrgInvitation($this->org, 'pending@example.com', $this->owner);
        $otherOrg = new OrgInvitation($org2, 'other@example.com', $owner2);
        $accepted = new OrgInvitation($this->org, 'accepted@example.com', $this->owner);
        $accepted->accept();
        $expired = new OrgInvitation($this->org, 'expired@example.com', $this->owner);
        $this->setExpiresAt($expired, new DateTimeImmutable('-1 hour'));

        $this->em->persist($pending);
        $this->em->persist($otherOrg);
        $this->em->persist($accepted);
        $this->em->persist($expired);
        $this->em->flush();

        $results = $this->repo->findPendingByOrg($this->org);

        $this->assertCount(1, $results);
        $this->assertSame('pending@example.com', $results[0]->getEmail());
    }

    #[Test]
    public function findPendingByOrgReturnsEmptyArrayWhenNoPendingInvitations(): void
    {
        $this->assertSame([], $this->repo->findPendingByOrg($this->org));
    }

    #[Test]
    public function findPendingByOrgOrdersByCreatedAtAscending(): void
    {
        $first = new OrgInvitation($this->org, 'first@example.com', $this->owner);
        $second = new OrgInvitation($this->org, 'second@example.com', $this->owner);
        $this->em->persist($first);
        $this->em->flush();
        $this->em->persist($second);
        $this->em->flush();

        $results = $this->repo->findPendingByOrg($this->org);

        $this->assertCount(2, $results);
        $this->assertSame('first@example.com', $results[0]->getEmail());
        $this->assertSame('second@example.com', $results[1]->getEmail());
    }

    private function setExpiresAt(OrgInvitation $invitation, DateTimeImmutable $expiresAt): void
    {
        $prop = new ReflectionProperty(OrgInvitation::class, 'expiresAt');
        $prop->setValue($invitation, $expiresAt);
    }
}
