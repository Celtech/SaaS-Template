<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthAccessToken;
use App\Entity\OAuthClient;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthAccessToken> */
class OAuthAccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthAccessToken::class);
    }

    public function findByTokenHash(string $hash): ?OAuthAccessToken
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

    public function save(OAuthAccessToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
