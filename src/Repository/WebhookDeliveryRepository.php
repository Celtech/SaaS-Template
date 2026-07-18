<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** @return WebhookDelivery[] */
    public function findFiltered(?string $eventType, ?WebhookDeliveryStatus $status, int $page = 1, int $perPage = 50): array
    {
        return $this->filteredQueryBuilder($eventType, $status)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(?string $eventType, ?WebhookDeliveryStatus $status): int
    {
        return (int) $this->filteredQueryBuilder($eventType, $status)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filteredQueryBuilder(?string $eventType, ?WebhookDeliveryStatus $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->join('d.endpoint', 'e')
            ->join('e.organization', 'o')
            ->addSelect('e', 'o');

        if ($eventType !== null) {
            $qb->andWhere('d.eventType LIKE :eventType')->setParameter('eventType', $eventType . '%');
        }

        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }
}
