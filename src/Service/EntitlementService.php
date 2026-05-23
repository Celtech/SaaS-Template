<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EntitlementType;
use App\Entity\Organization;
use App\Repository\SubscriptionRepository;

/**
 * Runtime entitlement and subscription access checks.
 *
 * Every check verifies subscription accessibility first. If the org has no
 * active/trialing subscription the check fails regardless of plan entitlements.
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
final class EntitlementService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {
    }

    /**
     * Returns true when the org's subscription is active/trialing.
     * Background jobs MUST call this before doing work for an org.
     *
     * @example
     *   // In any background job or scheduler task:
     *   if (!$this->entitlementService->isOrgAccessible($org)) {
     *       return; // skip — subscription lapsed
     *   }
     */
    public function isOrgAccessible(Organization $org): bool
    {
        $subscription = $this->subscriptionRepository->findForOrg($org);

        if ($subscription === null) {
            return false;
        }

        return $subscription->isAccessible();
    }

    /**
     * Returns true for boolean entitlements that are enabled on the org's plan,
     * or for integer/unlimited entitlements that have a non-zero value.
     * Always returns false when the subscription is not accessible.
     */
    public function isEnabled(Organization $org, string $slug): bool
    {
        $planEntitlement = $this->getPlanEntitlement($org, $slug);

        if ($planEntitlement === null) {
            return false;
        }

        return match ($planEntitlement->getEntitlement()->getType()) {
            EntitlementType::Boolean => $planEntitlement->getBoolValue(),
            EntitlementType::Integer => $planEntitlement->getIntValue() !== 0,
            EntitlementType::Unlimited => true,
        };
    }

    /**
     * Returns the integer limit for the entitlement, or -1 for unlimited.
     * Returns 0 when the subscription is not accessible or the entitlement
     * is not on the plan.
     *
     * @example
     *   $max = $this->entitlementService->limit($org, 'max_seats');
     *   if ($max !== -1 && $currentCount >= $max) {
     *       throw new \RuntimeException('Seat limit reached.');
     *   }
     */
    public function limit(Organization $org, string $slug): int
    {
        $planEntitlement = $this->getPlanEntitlement($org, $slug);

        if ($planEntitlement === null) {
            return 0;
        }

        return match ($planEntitlement->getEntitlement()->getType()) {
            EntitlementType::Unlimited => -1,
            EntitlementType::Integer, EntitlementType::Boolean => $planEntitlement->getIntValue(),
        };
    }

    private function getPlanEntitlement(Organization $org, string $slug): ?\App\Entity\PlanEntitlement
    {
        if (!$this->isOrgAccessible($org)) {
            return null;
        }

        $subscription = $this->subscriptionRepository->findForOrg($org);

        if ($subscription === null) {
            return null;
        }

        foreach ($subscription->getPlan()->getPlanEntitlements() as $pe) {
            if ($pe->getEntitlement()->getSlug() === $slug) {
                return $pe;
            }
        }

        return null;
    }
}
