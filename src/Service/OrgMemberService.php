<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use App\Repository\UserRoleRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

final class OrgMemberService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRoleRepository $userRoleRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function removeMember(Organization $org, User $member, User $actor): void
    {
        if ($member->getId()->equals($actor->getId())) {
            throw new LogicException('You cannot remove yourself from the organization.');
        }

        if ($member->getId()->equals($org->getOwner()->getId())) {
            throw new LogicException('The organization owner cannot be removed. Transfer ownership first.');
        }

        foreach ($this->userRoleRepository->findForUser($member, $org->getId()) as $userRole) {
            $this->em->remove($userRole);
        }

        $member->setOrganization(null);
        $this->em->flush();

        $this->auditLogger->logOrgEvent(
            'member.removed',
            $actor->getId()->toRfc4122(),
            $member->getId()->toRfc4122(),
            'user',
            null,
            ['org_id' => $org->getId()->toRfc4122()],
        );
    }

    public function changeMemberRole(Organization $org, User $member, Role $newRole, User $actor): void
    {
        if ($member->getId()->equals($org->getOwner()->getId())) {
            throw new LogicException('The organization owner\'s role cannot be changed.');
        }

        $existingRoles = $this->userRoleRepository->findForUser($member, $org->getId());
        $oldRoleSlug = \count($existingRoles) > 0 ? $existingRoles[0]->getRole()->getSlug() : null;

        foreach ($existingRoles as $userRole) {
            $this->em->remove($userRole);
        }

        $this->em->persist(new UserRole($member, $newRole, $org->getId()));
        $this->em->flush();

        $this->auditLogger->logOrgEvent(
            'member.role.changed',
            $actor->getId()->toRfc4122(),
            $member->getId()->toRfc4122(),
            'user',
            ['role' => $oldRoleSlug, 'org_id' => $org->getId()->toRfc4122()],
            ['role' => $newRole->getSlug(), 'org_id' => $org->getId()->toRfc4122()],
        );
    }
}
