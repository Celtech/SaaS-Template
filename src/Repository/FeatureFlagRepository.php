<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeatureFlag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeatureFlag>
 */
class FeatureFlagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureFlag::class);
    }

    public function findByKey(string $key): ?FeatureFlag
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return FeatureFlag[] */
    public function findAllOrderedByKey(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
