<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WebhookDelivery> */
class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    /** @return WebhookDelivery[] */
    public function findForEndpoint(WebhookEndpoint $endpoint, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.endpoint = :endpoint')
            ->setParameter('endpoint', $endpoint)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return WebhookDelivery[] */
    public function findDue(int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->andWhere('d.nextAttemptAt <= :now')
            ->setParameter('status', WebhookDeliveryStatus::Failed)
            ->setParameter('now', new DateTimeImmutable())
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(WebhookDelivery $delivery, bool $flush = false): void
    {
        $this->getEntityManager()->persist($delivery);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
