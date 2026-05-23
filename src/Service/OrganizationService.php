<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserRole;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    public function createForUser(User $user, string $name): Organization
    {
        $org = new Organization($name, $user);
        $this->em->persist($org);

        $user->setOrganization($org);

        $ownerRole = $this->roleRepository->findBySlug('org-owner');
        if ($ownerRole !== null) {
            $this->em->persist(new UserRole($user, $ownerRole, $org->getId()));
        }

        $this->em->flush();

        return $org;
    }
}
