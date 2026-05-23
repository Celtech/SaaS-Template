<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\OrganizationProviderInterface;
use App\Service\EntitlementService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions for entitlement checks in templates.
 *
 * @example
 *   {# Boolean gate — hide UI completely when feature is off #}
 *   {% if entitlement('can_use_webhooks') %}
 *       <a href="{{ path('webhooks_index') }}">Webhooks</a>
 *   {% else %}
 *       <span title="Upgrade to Pro">Webhooks 🔒</span>
 *   {% endif %}
 *
 *   {# Integer limit — show remaining capacity #}
 *   {% set max = entitlement_limit('max_seats') %}
 *   {% if max == -1 %}
 *       Unlimited seats
 *   {% else %}
 *       {{ members|length }} / {{ max }} seats used
 *   {% endif %}
 *
 *   {# Guard before allowing an action #}
 *   {% if entitlement_limit('max_api_keys') == -1 or apiKeys|length < entitlement_limit('max_api_keys') %}
 *       <a href="{{ path('api_keys_new') }}">Add API key</a>
 *   {% endif %}
 */
final class EntitlementExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly OrganizationProviderInterface $organizationProvider,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('entitlement', $this->isEnabled(...)),
            new TwigFunction('entitlement_limit', $this->limit(...)),
        ];
    }

    public function isEnabled(string $slug): bool
    {
        $org = $this->organizationProvider->getOrganization();

        if ($org === null) {
            return false;
        }

        return $this->entitlementService->isEnabled($org, $slug);
    }

    public function limit(string $slug): int
    {
        $org = $this->organizationProvider->getOrganization();

        if ($org === null) {
            return 0;
        }

        return $this->entitlementService->limit($org, $slug);
    }
}
