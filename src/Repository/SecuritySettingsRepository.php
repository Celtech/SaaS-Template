<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecuritySettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecuritySettings>
 */
class SecuritySettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecuritySettings::class);
    }

    public function getSettings(): SecuritySettings
    {
        $settings = $this->find(SecuritySettings::SINGLETON_ID);

        if ($settings === null) {
            $settings = new SecuritySettings();
            $this->getEntityManager()->persist($settings);
            $this->getEntityManager()->flush();
        }

        return $settings;
    }
}
