<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BillingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingSettings>
 */
class BillingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingSettings::class);
    }

    public function getSettings(): BillingSettings
    {
        $settings = $this->find(BillingSettings::SINGLETON_ID);

        if ($settings === null) {
            $settings = new BillingSettings();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}
