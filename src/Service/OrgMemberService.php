<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserRole;
use App\Repository\UserRoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

final class OrgMemberService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRoleRepository $userRoleRepository,
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
    }

    public function changeMemberRole(Organization $org, User $member, Role $newRole): void
    {
        if ($member->getId()->equals($org->getOwner()->getId())) {
            throw new LogicException('The organization owner\'s role cannot be changed.');
        }

        foreach ($this->userRoleRepository->findForUser($member, $org->getId()) as $userRole) {
            $this->em->remove($userRole);
        }

        $this->em->persist(new UserRole($member, $newRole, $org->getId()));
        $this->em->flush();
    }
}
