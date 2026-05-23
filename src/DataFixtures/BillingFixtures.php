<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Entitlement;
use App\Entity\EntitlementType;
use App\Entity\Plan;
use App\Entity\PlanEntitlement;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds the default plans, entitlements, and plan-entitlement mappings.
 *
 * Stripe product and price IDs below correspond to test-mode objects created
 * during initial project setup. Replace with live-mode IDs before going to
 * production (or manage via admin UI, which syncs to Stripe automatically).
 *
 * Stripe test-mode IDs (account: acct_195uzkJ2HhjxokWx):
 *   Free:       prod_UZRy1S4Vj0DDyZ  / price_1TaJ57J2HhjxokWxkUXo0C6b ($0/mo)
 *   Basic:      prod_UZRyf1HijHFfu9  / price_1TaJ58J2HhjxokWxmLMgm89x ($12/mo)
 *                                    / price_1TaJ58J2HhjxokWx7mHgim3L ($99/yr)
 *   Pro:        prod_UZRyCmH7yCSA2Q  / price_1TaJ59J2HhjxokWxHdlqmJSC ($29/mo)
 *                                    / price_1TaJ59J2HhjxokWxoJYPX3Gv ($249/yr)
 *   Ultimate:   prod_UZRyIR2ikoCa1f  / price_1TaJ5AJ2HhjxokWxARfILTKs ($79/mo)
 *                                    / price_1TaJ5AJ2HhjxokWxtOchiulO ($699/yr)
 *   Enterprise: prod_UZRyWkIAZ6Jijy  / price_1TaJ5CJ2HhjxokWxMLSF9HR1 ($299/mo)
 *                                    / price_1TaJ5CJ2HhjxokWxqazZlOso ($2,490/yr)
 */
final class BillingFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // --- Entitlements ---
        $entitlements = $this->createEntitlements($manager);

        // --- Plans ---
        $free = $this->createPlan(
            manager: $manager,
            slug: 'free',
            name: 'Free',
            description: 'Get started at no cost. Limited to core features.',
            sortOrder: 0,
            isFree: true,
            monthlyPriceCents: 0,
            annualPriceCents: 0,
            stripeProductId: 'prod_UZRy1S4Vj0DDyZ',
            stripePriceIdMonthly: 'price_1TaJ57J2HhjxokWxkUXo0C6b',
            stripePriceIdAnnual: null,
        );

        $basic = $this->createPlan(
            manager: $manager,
            slug: 'basic',
            name: 'Basic',
            description: 'Essential features for individuals and small teams.',
            sortOrder: 1,
            isFree: false,
            monthlyPriceCents: 1200,
            annualPriceCents: 9900,
            stripeProductId: 'prod_UZRyf1HijHFfu9',
            stripePriceIdMonthly: 'price_1TaJ58J2HhjxokWxmLMgm89x',
            stripePriceIdAnnual: 'price_1TaJ58J2HhjxokWx7mHgim3L',
        );

        $pro = $this->createPlan(
            manager: $manager,
            slug: 'pro',
            name: 'Pro',
            description: 'Advanced features for growing teams.',
            sortOrder: 2,
            isFree: false,
            monthlyPriceCents: 2900,
            annualPriceCents: 24900,
            stripeProductId: 'prod_UZRyCmH7yCSA2Q',
            stripePriceIdMonthly: 'price_1TaJ59J2HhjxokWxHdlqmJSC',
            stripePriceIdAnnual: 'price_1TaJ59J2HhjxokWxoJYPX3Gv',
        );

        $ultimate = $this->createPlan(
            manager: $manager,
            slug: 'ultimate',
            name: 'Ultimate',
            description: 'Full feature access with priority support.',
            sortOrder: 3,
            isFree: false,
            monthlyPriceCents: 7900,
            annualPriceCents: 69900,
            stripeProductId: 'prod_UZRyIR2ikoCa1f',
            stripePriceIdMonthly: 'price_1TaJ5AJ2HhjxokWxARfILTKs',
            stripePriceIdAnnual: 'price_1TaJ5AJ2HhjxokWxtOchiulO',
        );

        $enterprise = $this->createPlan(
            manager: $manager,
            slug: 'enterprise',
            name: 'Enterprise',
            description: 'Seat-based plan for large organizations with dedicated support.',
            sortOrder: 4,
            isFree: false,
            monthlyPriceCents: 29900,
            annualPriceCents: 249000,
            stripeProductId: 'prod_UZRyWkIAZ6Jijy',
            stripePriceIdMonthly: 'price_1TaJ5CJ2HhjxokWxMLSF9HR1',
            stripePriceIdAnnual: 'price_1TaJ5CJ2HhjxokWxqazZlOso',
        );

        // --- Plan → Entitlement mappings ---
        // Free
        $this->assign($manager, $free, $entitlements['max_seats'], '1');
        $this->assign($manager, $free, $entitlements['max_api_keys'], '0');
        $this->assign($manager, $free, $entitlements['can_use_api_keys'], '0');
        $this->assign($manager, $free, $entitlements['max_webhooks'], '0');
        $this->assign($manager, $free, $entitlements['can_use_webhooks'], '0');
        $this->assign($manager, $free, $entitlements['can_export'], '0');
        $this->assign($manager, $free, $entitlements['can_use_audit_log'], '0');
        $this->assign($manager, $free, $entitlements['support_tier'], '1'); // community

        // Basic
        $this->assign($manager, $basic, $entitlements['max_seats'], '3');
        $this->assign($manager, $basic, $entitlements['max_api_keys'], '2');
        $this->assign($manager, $basic, $entitlements['can_use_api_keys'], '1');
        $this->assign($manager, $basic, $entitlements['max_webhooks'], '0');
        $this->assign($manager, $basic, $entitlements['can_use_webhooks'], '0');
        $this->assign($manager, $basic, $entitlements['can_export'], '1');
        $this->assign($manager, $basic, $entitlements['can_use_audit_log'], '0');
        $this->assign($manager, $basic, $entitlements['support_tier'], '2'); // email

        // Pro
        $this->assign($manager, $pro, $entitlements['max_seats'], '10');
        $this->assign($manager, $pro, $entitlements['max_api_keys'], '10');
        $this->assign($manager, $pro, $entitlements['can_use_api_keys'], '1');
        $this->assign($manager, $pro, $entitlements['max_webhooks'], '5');
        $this->assign($manager, $pro, $entitlements['can_use_webhooks'], '1');
        $this->assign($manager, $pro, $entitlements['can_export'], '1');
        $this->assign($manager, $pro, $entitlements['can_use_audit_log'], '1');
        $this->assign($manager, $pro, $entitlements['support_tier'], '3'); // priority email

        // Ultimate
        $this->assign($manager, $ultimate, $entitlements['max_seats'], '-1');
        $this->assign($manager, $ultimate, $entitlements['max_api_keys'], '-1');
        $this->assign($manager, $ultimate, $entitlements['can_use_api_keys'], '1');
        $this->assign($manager, $ultimate, $entitlements['max_webhooks'], '-1');
        $this->assign($manager, $ultimate, $entitlements['can_use_webhooks'], '1');
        $this->assign($manager, $ultimate, $entitlements['can_export'], '1');
        $this->assign($manager, $ultimate, $entitlements['can_use_audit_log'], '1');
        $this->assign($manager, $ultimate, $entitlements['support_tier'], '4'); // priority + chat

        // Enterprise
        $this->assign($manager, $enterprise, $entitlements['max_seats'], '-1');
        $this->assign($manager, $enterprise, $entitlements['max_api_keys'], '-1');
        $this->assign($manager, $enterprise, $entitlements['can_use_api_keys'], '1');
        $this->assign($manager, $enterprise, $entitlements['max_webhooks'], '-1');
        $this->assign($manager, $enterprise, $entitlements['can_use_webhooks'], '1');
        $this->assign($manager, $enterprise, $entitlements['can_export'], '1');
        $this->assign($manager, $enterprise, $entitlements['can_use_audit_log'], '1');
        $this->assign($manager, $enterprise, $entitlements['support_tier'], '5'); // dedicated

        $manager->flush();
    }

    /** @return array<string, Entitlement> */
    private function createEntitlements(ObjectManager $manager): array
    {
        $defs = [
            // Seats
            ['max_seats', 'Maximum Seats', EntitlementType::Integer, 'Maximum number of members allowed in the organization. -1 = unlimited.'],

            // API keys
            ['can_use_api_keys', 'API Key Access', EntitlementType::Boolean, 'Whether the organization can create and use API keys.'],
            ['max_api_keys', 'Maximum API Keys', EntitlementType::Integer, 'Maximum number of API keys. -1 = unlimited.'],

            // Webhooks
            ['can_use_webhooks', 'Webhook Access', EntitlementType::Boolean, 'Whether the organization can configure outgoing webhooks.'],
            ['max_webhooks', 'Maximum Webhooks', EntitlementType::Integer, 'Maximum number of webhook endpoints. -1 = unlimited.'],

            // Features
            ['can_export', 'Data Export', EntitlementType::Boolean, 'Whether the organization can export data.'],
            ['can_use_audit_log', 'Audit Log Access', EntitlementType::Boolean, 'Whether the organization can view the audit log.'],

            // Support (integer tier: 1=community, 2=email, 3=priority email, 4=priority+chat, 5=dedicated)
            ['support_tier', 'Support Tier', EntitlementType::Integer, 'Support level: 1=community, 2=email, 3=priority email, 4=priority+chat, 5=dedicated.'],
        ];

        $map = [];
        foreach ($defs as [$slug, $name, $type, $desc]) {
            $e = new Entitlement($slug, $name, $type);
            $e->setDescription($desc);
            $manager->persist($e);
            $map[$slug] = $e;
        }

        return $map;
    }

    private function createPlan(
        ObjectManager $manager,
        string $slug,
        string $name,
        string $description,
        int $sortOrder,
        bool $isFree,
        int $monthlyPriceCents,
        int $annualPriceCents,
        string $stripeProductId,
        string $stripePriceIdMonthly,
        ?string $stripePriceIdAnnual,
    ): Plan {
        $plan = new Plan($slug, $name);
        $plan->setDescription($description);
        $plan->setSortOrder($sortOrder);
        $plan->setIsFree($isFree);
        $plan->setMonthlyPriceCents($monthlyPriceCents);
        $plan->setAnnualPriceCents($annualPriceCents);
        $plan->setStripeProductId($stripeProductId);
        $plan->setStripePriceIdMonthly($stripePriceIdMonthly);
        $plan->setStripePriceIdAnnual($stripePriceIdAnnual);
        $manager->persist($plan);

        return $plan;
    }

    private function assign(ObjectManager $manager, Plan $plan, Entitlement $entitlement, string $value): void
    {
        $manager->persist(new PlanEntitlement($plan, $entitlement, $value));
    }
}
