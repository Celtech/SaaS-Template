<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthClient;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthClient> */
class OAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthClient::class);
    }

    public function findByClientId(string $clientId): ?OAuthClient
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }

    /** @return OAuthClient[] */
    public function findForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.organization = :org')
            ->setParameter('org', $organization)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(OAuthClient $client, bool $flush = false): void
    {
        $this->getEntityManager()->persist($client);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(OAuthClient $client, bool $flush = false): void
    {
        $this->getEntityManager()->remove($client);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
