<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthDeviceCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthDeviceCode> */
class OAuthDeviceCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthDeviceCode::class);
    }

    public function findByDeviceCodeHash(string $hash): ?OAuthDeviceCode
    {
        return $this->findOneBy(['deviceCodeHash' => $hash]);
    }

    public function findByUserCodeHash(string $hash): ?OAuthDeviceCode
    {
        return $this->findOneBy(['userCodeHash' => $hash]);
    }

    public function save(OAuthDeviceCode $deviceCode, bool $flush = false): void
    {
        $this->getEntityManager()->persist($deviceCode);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
