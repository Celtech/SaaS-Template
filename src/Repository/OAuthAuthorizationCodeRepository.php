<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthAuthorizationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthAuthorizationCode> */
class OAuthAuthorizationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthAuthorizationCode::class);
    }

    public function findByCodeHash(string $hash): ?OAuthAuthorizationCode
    {
        return $this->findOneBy(['codeHash' => $hash]);
    }

    public function save(OAuthAuthorizationCode $code, bool $flush = false): void
    {
        $this->getEntityManager()->persist($code);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
