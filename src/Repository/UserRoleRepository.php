<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Permission;
use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserRole>
 */
class UserRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRole::class);
    }

    /** @return UserRole[] */
    public function findForUser(User $user, ?Uuid $contextId = null): array
    {
        return $this->findBy([
            'user' => $user,
            'contextId' => $contextId,
        ]);
    }

    public function userHasPermission(User $user, Permission $permission, ?Uuid $contextId = null): bool
    {
        $count = (int) $this->createQueryBuilder('ur')
            ->select('COUNT(ur.id)')
            ->join('ur.role', 'r')
            ->join('r.permissions', 'rp')
            ->where('ur.user = :user')
            ->andWhere('ur.contextId = :contextId')
            ->andWhere('rp.permissionKey = :permissionKey')
            ->setParameter('user', $user)
            ->setParameter('contextId', $contextId)
            ->setParameter('permissionKey', $permission->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
