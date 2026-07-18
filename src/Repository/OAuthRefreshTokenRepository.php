<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthClient;
use App\Entity\OAuthRefreshToken;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthRefreshToken> */
class OAuthRefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthRefreshToken::class);
    }

    public function findByTokenHash(string $hash): ?OAuthRefreshToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    public function revokeAllForClient(OAuthClient $client): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.client = :client')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('client', $client)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    public function save(OAuthRefreshToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
