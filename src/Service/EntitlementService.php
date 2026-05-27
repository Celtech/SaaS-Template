<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EntitlementType;
use App\Entity\Organization;
use App\Repository\SubscriptionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Runtime entitlement and subscription access checks.
 *
 * Every check verifies subscription accessibility first. If the org has no
 * active/trialing subscription the check fails regardless of plan entitlements.
 *
 * Results are cached in Valkey per org. Call invalidateForOrg() after any
 * subscription status or plan change to bust the cache immediately.
 *
 * Usage in controllers / services:
 *   if (!$this->entitlementService->isEnabled($org, 'can_use_webhooks')) {
 *       throw new AccessDeniedException();
 *   }
 *   $limit = $this->entitlementService->limit($org, 'max_seats'); // -1 = unlimited
 *
 * Usage in Twig:
 *   {% if entitlement('can_export') %} ... {% endif %}
 *   {% if entitlement_limit('max_seats') == -1 or members|length < entitlement_limit('max_seats') %}
 */
class EntitlementService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        #[Autowire(service: 'cache.entitlements')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Returns true when the org's subscription is active/trialing.
     * Background jobs MUST call this before doing work for an org.
     */
    public function isOrgAccessible(Organization $org): bool
    {
        return $this->getEntitlementMap($org)['accessible'];
    }

    /**
     * Returns true for boolean entitlements that are enabled on the org's plan,
     * or for integer/unlimited entitlements that have a non-zero value.
     * Always returns false when the subscription is not accessible.
     */
    public function isEnabled(Organization $org, string $slug): bool
    {
        $map = $this->getEntitlementMap($org);

        if (!$map['accessible'] || !isset($map['entitlements'][$slug])) {
            return false;
        }

        $entry = $map['entitlements'][$slug];

        return match (EntitlementType::from($entry['type'])) {
            EntitlementType::Boolean => (bool) $entry['value'],
            EntitlementType::Integer => (int) $entry['value'] !== 0,
            EntitlementType::Unlimited => true,
        };
    }

    /**
     * Returns the integer limit for the entitlement, or -1 for unlimited.
     * Returns 0 when the subscription is not accessible or the entitlement
     * is not on the plan.
     */
    public function limit(Organization $org, string $slug): int
    {
        $map = $this->getEntitlementMap($org);

        if (!$map['accessible'] || !isset($map['entitlements'][$slug])) {
            return 0;
        }

        $entry = $map['entitlements'][$slug];

        return match (EntitlementType::from($entry['type'])) {
            EntitlementType::Unlimited => -1,
            EntitlementType::Integer, EntitlementType::Boolean => (int) $entry['value'],
        };
    }

    /**
     * Removes the cached entitlement map for the given org.
     * Must be called after any subscription status or plan change.
     */
    public function invalidateForOrg(Organization $org): void
    {
        $this->cache->delete($this->cacheKey($org));
    }

    /**
     * Loads (or returns cached) the full entitlement map for the org.
     *
     * Shape:
     *   ['accessible' => bool, 'entitlements' => ['slug' => ['type' => string, 'value' => string]]]
     *
     * @return array{accessible: bool, entitlements: array<string, array{type: string, value: string}>}
     */
    private function getEntitlementMap(Organization $org): array
    {
        /* @var array{accessible: bool, entitlements: array<string, array{type: string, value: string}>} */
        return $this->cache->get($this->cacheKey($org), function (ItemInterface $item) use ($org): array {
            $subscription = $this->subscriptionRepository->findForOrg($org);

            if ($subscription === null || !$subscription->isAccessible()) {
                return ['accessible' => false, 'entitlements' => []];
            }

            $entitlements = [];
            foreach ($subscription->getPlan()->getPlanEntitlements() as $pe) {
                $entitlements[$pe->getEntitlement()->getSlug()] = [
                    'type' => $pe->getEntitlement()->getType()->value,
                    'value' => $pe->getValue(),
                ];
            }

            return ['accessible' => true, 'entitlements' => $entitlements];
        });
    }

    private function cacheKey(Organization $org): string
    {
        return 'entitlements_' . $org->getId()->toRfc4122();
    }
}
