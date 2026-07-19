<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Notification> */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnreadInApp(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('channel', 'in_app')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Notification[] */
    public function findInAppForUser(User $user, ?string $type = null, int $limit = 50, int $offset = 0): array
    {
        return $this->filteredQueryBuilder($user, $type)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countInAppForUser(User $user, ?string $type = null): int
    {
        return (int) $this->filteredQueryBuilder($user, $type)
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filteredQueryBuilder(User $user, ?string $type): QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->setParameter('user', $user)
            ->setParameter('channel', 'in_app');

        if ($type !== null) {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    public function markAllAsReadForUser(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('channel', 'in_app')
            ->getQuery()
            ->execute();
    }

    public function save(Notification $notification, bool $flush = false): void
    {
        $this->getEntityManager()->persist($notification);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
