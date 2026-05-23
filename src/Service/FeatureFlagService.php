<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FeatureFlagRepository;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class FeatureFlagService
{
    private const CACHE_TTL = 300;
    private const CACHE_PREFIX = 'feature_flag_';

    public function __construct(
        private readonly FeatureFlagRepository $repository,
        private readonly CacheInterface $cache,
    ) {
    }

    public function isEnabled(string $key): bool
    {
        try {
            return $this->cache->get(self::CACHE_PREFIX . $key, function (ItemInterface $item) use ($key): bool {
                $item->expiresAfter(self::CACHE_TTL);
                $flag = $this->repository->findByKey($key);

                return $flag?->isEnabled() ?? false;
            });
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Invalidates the cache for a specific flag. Call this after toggling a flag in the admin.
     */
    public function invalidate(string $key): void
    {
        try {
            $this->cache->delete(self::CACHE_PREFIX . $key);
        } catch (InvalidArgumentException) {
        }
    }
}
