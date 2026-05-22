<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\SecuritySettings;
use App\Repository\SecuritySettingsRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class SecuritySettingsRepositoryTest extends IntegrationTestCase
{
    private SecuritySettingsRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = static::getContainer()->get(SecuritySettingsRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function getSettingsReturnsExistingRowWithDefaultValues(): void
    {
        $settings = $this->repo->getSettings();

        $this->assertSame(SecuritySettings::SINGLETON_ID, $settings->getId());
        $this->assertSame(SecuritySettings::DEFAULT_MAX_FAILED_ATTEMPTS, $settings->getMaxFailedAttempts());
        $this->assertSame(SecuritySettings::DEFAULT_LOCKOUT_MINUTES, $settings->getLockoutMinutes());
    }

    #[Test]
    public function getSettingsReturnsSameObjectOnRepeatedCalls(): void
    {
        $first = $this->repo->getSettings();
        $second = $this->repo->getSettings();

        $this->assertSame($first->getId(), $second->getId());

        $count = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM security_settings');
        $this->assertSame(1, $count);
    }

    #[Test]
    public function getSettingsAutoCreatesRowWhenNoneExists(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM security_settings');
        $this->em->clear();

        $settings = $this->repo->getSettings();

        $this->assertSame(SecuritySettings::SINGLETON_ID, $settings->getId());
        $this->assertSame(SecuritySettings::DEFAULT_MAX_FAILED_ATTEMPTS, $settings->getMaxFailedAttempts());
        $this->assertSame(SecuritySettings::DEFAULT_LOCKOUT_MINUTES, $settings->getLockoutMinutes());

        $count = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM security_settings');
        $this->assertSame(1, $count);
    }
}
