<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * AuditLog records are immutable. This repository intentionally exposes no
 * save(), update(), or remove() methods. Records are written via AuditLogger
 * using DBAL directly to guarantee writes survive outer transaction rollbacks.
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return AuditLog[]
     */
    public function findByActor(string $actorId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.actorId = :actorId')
            ->setParameter('actorId', $actorId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findBySubject(string $subjectId, string $subjectType, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.subjectId = :subjectId AND a.subjectType = :subjectType')
            ->setParameter('subjectId', $subjectId)
            ->setParameter('subjectType', $subjectType)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findByAction(string $action, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.action LIKE :action')
            ->setParameter('action', $action . '%')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findFiltered(?string $action, ?string $actorId, ?string $sessionId = null, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.createdAt', 'DESC');

        if ($action !== null) {
            $qb->andWhere('a.action LIKE :action')->setParameter('action', $action . '%');
        }
        if ($actorId !== null) {
            $qb->andWhere('a.actorId = :actorId')->setParameter('actorId', $actorId);
        }
        if ($sessionId !== null) {
            $qb->andWhere('a.impersonationSessionId = :sessionId')->setParameter('sessionId', $sessionId);
        }

        return $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(?string $action, ?string $actorId, ?string $sessionId = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        if ($action !== null) {
            $qb->andWhere('a.action LIKE :action')->setParameter('action', $action . '%');
        }
        if ($actorId !== null) {
            $qb->andWhere('a.actorId = :actorId')->setParameter('actorId', $actorId);
        }
        if ($sessionId !== null) {
            $qb->andWhere('a.impersonationSessionId = :sessionId')->setParameter('sessionId', $sessionId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
