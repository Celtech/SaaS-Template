<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\FeatureFlag;
use App\Repository\FeatureFlagRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class FeatureFlagRepositoryTest extends IntegrationTestCase
{
    private FeatureFlagRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = static::getContainer()->get(FeatureFlagRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function findByKeyReturnsNullWhenFlagDoesNotExist(): void
    {
        $this->assertNull($this->repo->findByKey('nonexistent'));
    }

    #[Test]
    public function findByKeyReturnsFlagWhenItExists(): void
    {
        $flag = new FeatureFlag('my_feature', true, 'A test flag');
        $this->em->persist($flag);
        $this->em->flush();

        $found = $this->repo->findByKey('my_feature');

        $this->assertNotNull($found);
        $this->assertSame('my_feature', $found->getKey());
        $this->assertTrue($found->isEnabled());
        $this->assertSame('A test flag', $found->getDescription());
    }

    #[Test]
    public function findAllOrderedByKeyReturnsFlagsInAlphabeticalOrder(): void
    {
        $this->em->persist(new FeatureFlag('zebra_feature'));
        $this->em->persist(new FeatureFlag('alpha_feature', true));
        $this->em->persist(new FeatureFlag('middle_feature'));
        $this->em->flush();

        $flags = $this->repo->findAllOrderedByKey();

        $keys = array_map(static fn (FeatureFlag $f) => $f->getKey(), $flags);
        $this->assertSame(['alpha_feature', 'middle_feature', 'zebra_feature'], $keys);
    }

    #[Test]
    public function findAllOrderedByKeyReturnsEmptyArrayWhenNoFlagsExist(): void
    {
        $this->assertSame([], $this->repo->findAllOrderedByKey());
    }

    #[Test]
    public function enableAndDisableUpdateEnabledStateAndTimestamp(): void
    {
        $flag = new FeatureFlag('toggle_feature', false);
        $this->em->persist($flag);
        $this->em->flush();

        $flag->enable();
        $this->em->flush();
        $this->em->clear();

        $refreshed = $this->repo->findByKey('toggle_feature');
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isEnabled());

        $refreshed->disable();
        $this->em->flush();
        $this->em->clear();

        $final = $this->repo->findByKey('toggle_feature');
        $this->assertNotNull($final);
        $this->assertFalse($final->isEnabled());
    }
}
