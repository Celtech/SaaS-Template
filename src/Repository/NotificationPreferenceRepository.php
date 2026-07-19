<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NotificationPreference> */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findOneForUserTypeChannel(User $user, string $notificationType, string $channel): ?NotificationPreference
    {
        return $this->findOneBy([
            'user' => $user,
            'notificationType' => $notificationType,
            'channel' => $channel,
        ]);
    }

    /** @return NotificationPreference[] */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function save(NotificationPreference $preference, bool $flush = false): void
    {
        $this->getEntityManager()->persist($preference);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
