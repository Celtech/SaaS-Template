<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Plan;
use App\Entity\PlanEntitlement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanEntitlement>
 */
class PlanEntitlementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanEntitlement::class);
    }

    /** @return PlanEntitlement[] */
    public function findForPlan(Plan $plan): array
    {
        return $this->createQueryBuilder('pe')
            ->join('pe.entitlement', 'e')
            ->where('pe.plan = :plan')
            ->setParameter('plan', $plan)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
