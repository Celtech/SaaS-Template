<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Plan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findBySlug(string $slug): ?Plan
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Plan[] Active plans shown on the public pricing page. */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.isPublic = true')
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Plan[] All active plans including private ones (for admin use). */
    public function findAllActiveIncludingPrivate(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the highest-priced active, public, non-free, non-custom-quote plan.
     * Used as the default trial plan when no plan token is present and no
     * defaultTrialPlanSlug is configured in BillingSettings.
     */
    public function findDefaultTrialPlan(): ?Plan
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.isPublic = true')
            ->andWhere('p.isFree = false')
            ->andWhere('p.isCustomQuote = false')
            ->orderBy('p.monthlyPriceCents', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStripeProductId(string $stripeProductId): ?Plan
    {
        return $this->findOneBy(['stripeProductId' => $stripeProductId]);
    }
}
