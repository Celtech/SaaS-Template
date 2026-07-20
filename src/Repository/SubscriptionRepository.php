<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findForOrg(Organization $org): ?Subscription
    {
        return $this->findOneBy(['organization' => $org]);
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    public function findByStripeCustomerId(string $stripeCustomerId): ?Subscription
    {
        return $this->findOneBy(['stripeCustomerId' => $stripeCustomerId]);
    }

    /**
     * Returns subscriptions in trialing status where the trial has ended.
     * Used by the daily trial expiry scheduler.
     *
     * @return Subscription[]
     */
    public function findExpiredTrials(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.trialEndsAt IS NOT NULL')
            ->andWhere('s.trialEndsAt < :now')
            ->setParameter('status', SubscriptionStatus::Trialing)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Trialing subscriptions whose trial ends within the given day window from now.
     * Used by the daily "trial expiring soon" notification — a 24h-wide window run
     * once a day means each trial is only ever caught by exactly one run.
     *
     * @return Subscription[]
     */
    public function findTrialsEndingBetween(int $fromDays, int $toDays): array
    {
        $from = new DateTimeImmutable(\sprintf('+%d days', $fromDays));
        $to = new DateTimeImmutable(\sprintf('+%d days', $toDays));

        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.trialEndsAt IS NOT NULL')
            ->andWhere('s.trialEndsAt >= :from')
            ->andWhere('s.trialEndsAt < :to')
            ->setParameter('status', SubscriptionStatus::Trialing)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns past_due subscriptions where the grace period has elapsed.
     * Used by the scheduler to hard-block background jobs.
     *
     * @return Subscription[]
     */
    public function findPastGracePeriod(int $gracePeriodDays): array
    {
        $cutoff = new DateTimeImmutable(\sprintf('-%d days', $gracePeriodDays));

        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.updatedAt < :cutoff')
            ->setParameter('status', SubscriptionStatus::PastDue)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
