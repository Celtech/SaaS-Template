<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * @return Organization[]
     */
    public function findWithSearch(?string $search, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($search !== null && $search !== '') {
            $qb->where('o.name LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countWithSearch(?string $search): int
    {
        $qb = $this->createQueryBuilder('o')->select('COUNT(o.id)');

        if ($search !== null && $search !== '') {
            $qb->where('o.name LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
