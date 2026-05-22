<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataErasureRequest;
use App\Enum\DataErasureStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataErasureRequest>
 */
class DataErasureRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataErasureRequest::class);
    }

    /** @return list<DataErasureRequest> */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', DataErasureStatus::Pending)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
