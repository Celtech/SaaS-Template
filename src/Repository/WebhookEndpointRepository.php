<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\WebhookEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WebhookEndpoint> */
class WebhookEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEndpoint::class);
    }

    /** @return WebhookEndpoint[] */
    public function findForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.organization = :org')
            ->setParameter('org', $organization)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return WebhookEndpoint[] */
    public function findActiveForOrganizationAndEvent(Organization $organization, string $event): array
    {
        $endpoints = $this->createQueryBuilder('e')
            ->where('e.organization = :org')
            ->andWhere('e.isActive = true')
            ->setParameter('org', $organization)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($endpoints, static fn (WebhookEndpoint $e) => $e->subscribesTo($event)));
    }

    public function save(WebhookEndpoint $endpoint, bool $flush = false): void
    {
        $this->getEntityManager()->persist($endpoint);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WebhookEndpoint $endpoint, bool $flush = false): void
    {
        $this->getEntityManager()->remove($endpoint);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
