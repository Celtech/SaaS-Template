<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSession>
 */
class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    /** @return list<UserSession> */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('s.lastActiveAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByTokenHash(string $hash): ?UserSession
    {
        return $this->findOneBy(['sessionTokenHash' => $hash, 'revokedAt' => null]);
    }

    /** Has this user ever had a session (active or not) from this IP before? Used for new-login detection. */
    public function hasSessionFromIp(User $user, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return true; // can't compare — assume known rather than notify on every login
        }

        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.user = :user')
            ->andWhere('s.ipAddress = :ip')
            ->setParameter('user', $user)
            ->setParameter('ip', $ip)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function revokeAllForUser(User $user, ?string $exceptTokenHash = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->update()
            ->set('s.revokedAt', ':now')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new DateTimeImmutable());

        if ($exceptTokenHash !== null) {
            $qb->andWhere('s.sessionTokenHash != :except')
                ->setParameter('except', $exceptTokenHash);
        }

        return $qb->getQuery()->execute();
    }
}
