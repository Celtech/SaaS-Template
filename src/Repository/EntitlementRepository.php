<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entitlement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entitlement>
 */
class EntitlementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entitlement::class);
    }

    public function findBySlug(string $slug): ?Entitlement
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
