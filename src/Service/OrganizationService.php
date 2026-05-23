<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserRole;
use App\Repository\RoleRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository $roleRepository,
        private readonly AuditLogger $auditLogger,
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

        $this->auditLogger->logOrgEvent('created', $user->getId()->toRfc4122(), $org->getId()->toRfc4122(), 'organization');

        return $org;
    }
}
