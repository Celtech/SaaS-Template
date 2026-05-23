<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\RoleContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findBySlug(string $slug): ?Role
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Role[] */
    public function findByContext(RoleContext $context): array
    {
        return $this->findBy(['context' => $context], ['name' => 'ASC']);
    }
}
