<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\FeatureFlag;
use App\Repository\FeatureFlagRepository;
use App\Service\FeatureFlagService;
use App\Tests\UnitTestCase;
use DateInterval;
use DateTimeInterface;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class FeatureFlagServiceTest extends UnitTestCase
{
    /** @var FeatureFlagRepository&Stub */
    private FeatureFlagRepository $repository;
    private FeatureFlagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createStub(FeatureFlagRepository::class);
    }

    #[Test]
    public function isEnabledReturnsTrueWhenFlagIsEnabled(): void
    {
        $flag = new FeatureFlag('my_feature', true);
        $this->repository->method('findByKey')->willReturn($flag);

        $this->service = new FeatureFlagService($this->repository, $this->makePassthroughCache());

        $this->assertTrue($this->service->isEnabled('my_feature'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenFlagIsDisabled(): void
    {
        $flag = new FeatureFlag('my_feature', false);
        $this->repository->method('findByKey')->willReturn($flag);

        $this->service = new FeatureFlagService($this->repository, $this->makePassthroughCache());

        $this->assertFalse($this->service->isEnabled('my_feature'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenFlagDoesNotExist(): void
    {
        $this->repository->method('findByKey')->willReturn(null);

        $this->service = new FeatureFlagService($this->repository, $this->makePassthroughCache());

        $this->assertFalse($this->service->isEnabled('unknown_flag'));
    }

    #[Test]
    public function isEnabledReturnsCachedValueWithoutHittingRepository(): void
    {
        $flag = new FeatureFlag('cached_feature', true);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('feature_flag_cached_feature')
            ->willReturn(true);

        $this->service = new FeatureFlagService($this->repository, $cache);

        $this->assertTrue($this->service->isEnabled('cached_feature'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenCacheThrowsInvalidArgumentException(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(
            new class('bad key') extends Exception implements InvalidArgumentException {}
        );

        $this->service = new FeatureFlagService($this->repository, $cache);

        $this->assertFalse($this->service->isEnabled('bad key'));
    }

    #[Test]
    public function invalidateDeletesCacheEntryForKey(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('delete')
            ->with('feature_flag_my_feature');

        $this->service = new FeatureFlagService($this->repository, $cache);

        $this->service->invalidate('my_feature');
    }

    #[Test]
    public function invalidateSilentlyIgnoresCacheException(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('delete')->willThrowException(
            new class('bad key') extends Exception implements InvalidArgumentException {}
        );

        $this->service = new FeatureFlagService($this->repository, $cache);

        $this->service->invalidate('bad key');
        $this->addToAssertionCount(1);
    }

    private function makePassthroughCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @param array<string, mixed>|null $metadata */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                $item = new class implements ItemInterface {
                    public function getKey(): string
                    {
                        return '';
                    }

                    public function get(): mixed
                    {
                        return null;
                    }

                    public function isHit(): bool
                    {
                        return false;
                    }

                    public function set(mixed $value): static
                    {
                        return $this;
                    }

                    public function expiresAt(?DateTimeInterface $expiration): static
                    {
                        return $this;
                    }

                    public function expiresAfter(int|DateInterval|null $time): static
                    {
                        return $this;
                    }

                    public function tag(string|iterable $tags): static
                    {
                        return $this;
                    }

                    /** @return array<string, mixed> */
                    public function getMetadata(): array
                    {
                        return [];
                    }
                };

                return $callback($item, false);
            }

            public function delete(string $key): bool
            {
                return true;
            }
        };
    }
}
