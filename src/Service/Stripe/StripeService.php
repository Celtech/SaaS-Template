<?php

declare(strict_types=1);

namespace App\Service\Stripe;

use App\Entity\Plan;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/**
 * Wraps Stripe API calls used by the admin plan management UI.
 *
 * All methods throw ApiErrorException on Stripe-side failures — callers
 * should catch and surface these as user-facing errors.
 */
final class StripeService
{
    private StripeClient $stripe;

    public function __construct(string $stripeSecretKey)
    {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    /**
     * Creates a Stripe Product for a plan. Call this when creating a new plan
     * in the admin UI before persisting the Plan entity.
     *
     * @throws ApiErrorException
     */
    public function createProduct(string $name, ?string $description = null): Product
    {
        $params = ['name' => $name, 'type' => 'service'];
        if ($description !== null) {
            $params['description'] = $description;
        }

        return $this->stripe->products->create($params);
    }

    /**
     * Updates a Stripe Product's name and description. Call this when the
     * plan name or description is changed in the admin UI.
     *
     * @throws ApiErrorException
     */
    public function updateProduct(string $stripeProductId, string $name, ?string $description = null): Product
    {
        return $this->stripe->products->update($stripeProductId, array_filter([
            'name' => $name,
            'description' => $description,
        ]));
    }

    /**
     * Archives a Stripe Product so it can no longer be used for new
     * subscriptions. Call this when deactivating a plan.
     *
     * @throws ApiErrorException
     */
    public function archiveProduct(string $stripeProductId): Product
    {
        return $this->stripe->products->update($stripeProductId, ['active' => false]);
    }

    /**
     * Creates a recurring Stripe Price for a product.
     * Stripe prices are immutable — call archivePrice() on the old price
     * before creating a replacement.
     *
     * @param int    $amountCents Price in the smallest currency unit (e.g. cents for USD)
     * @param string $interval    'month' or 'year'
     *
     * @throws ApiErrorException
     */
    public function createPrice(string $stripeProductId, int $amountCents, string $interval, string $currency = 'usd'): Price
    {
        return $this->stripe->prices->create([
            'product' => $stripeProductId,
            'unit_amount' => $amountCents,
            'currency' => $currency,
            'recurring' => ['interval' => $interval],
        ]);
    }

    /**
     * Archives a Stripe Price so it can no longer be used for new subscriptions.
     * Existing subscriptions on the price are not affected.
     *
     * @throws ApiErrorException
     */
    public function archivePrice(string $stripePriceId): Price
    {
        return $this->stripe->prices->update($stripePriceId, ['active' => false]);
    }

    /**
     * Creates a Stripe Customer for an organisation.
     * Store the returned customer ID on the Subscription entity after checkout completes.
     *
     * @throws ApiErrorException
     */
    public function createCustomer(string $email, string $orgName, string $orgId): Customer
    {
        return $this->stripe->customers->create([
            'email' => $email,
            'name' => $orgName,
            'metadata' => ['org_id' => $orgId],
        ]);
    }

    /**
     * Creates a Stripe Checkout Session for a new subscription.
     *
     * Pass $trialDays when the BillingSettings trial is enabled.
     * The session metadata carries plan_id and org_id so the success handler
     * and webhook processor can look up the right entities.
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(
        string $stripeCustomerId,
        string $stripePriceId,
        string $planId,
        string $orgId,
        string $successUrl,
        string $cancelUrl,
        ?int $trialDays = null,
    ): CheckoutSession {
        $params = [
            'customer' => $stripeCustomerId,
            'mode' => 'subscription',
            'line_items' => [['price' => $stripePriceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'plan_id' => $planId,
                'org_id' => $orgId,
            ],
            'subscription_data' => [
                'metadata' => [
                    'plan_id' => $planId,
                    'org_id' => $orgId,
                ],
            ],
        ];

        if ($trialDays !== null && $trialDays > 0) {
            $params['subscription_data']['trial_period_days'] = $trialDays;
        }

        return $this->stripe->checkout->sessions->create($params);
    }

    /**
     * Retrieves a completed Checkout Session with the subscription expanded.
     *
     * @throws ApiErrorException
     */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSession
    {
        return $this->stripe->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['subscription', 'subscription.latest_invoice']],
        );
    }

    /**
     * Retrieves a Stripe Subscription by its ID.
     *
     * @throws ApiErrorException
     */
    public function retrieveSubscription(string $stripeSubscriptionId): StripeSubscription
    {
        return $this->stripe->subscriptions->retrieve(
            $stripeSubscriptionId,
            ['expand' => ['latest_invoice']],
        );
    }

    /**
     * Creates a Stripe Billing Portal session so the customer can manage their
     * payment method, view invoices, and cancel their subscription.
     *
     * @throws ApiErrorException
     */
    public function createPortalSession(string $stripeCustomerId, string $returnUrl): PortalSession
    {
        return $this->stripe->billingPortal->sessions->create([
            'customer' => $stripeCustomerId,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Returns the most recent invoices for a Stripe customer.
     *
     * @return Collection<Invoice>
     *
     * @throws ApiErrorException
     */
    public function listInvoices(string $stripeCustomerId, int $limit = 12): Collection
    {
        return $this->stripe->invoices->all([
            'customer' => $stripeCustomerId,
            'limit' => $limit,
        ]);
    }

    /**
     * Creates or replaces both recurring prices for a plan (monthly + annual).
     * Archives the old prices if they exist on the Plan entity.
     * Updates $plan's Stripe price IDs in place — caller must flush the entity.
     *
     * @throws ApiErrorException
     */
    public function syncPlanPrices(Plan $plan): void
    {
        $productId = $plan->getStripeProductId();

        if ($productId === null) {
            return;
        }

        // Monthly
        if ($plan->getMonthlyPriceCents() > 0) {
            if ($plan->getStripePriceIdMonthly() !== null) {
                $this->archivePrice($plan->getStripePriceIdMonthly());
            }
            $price = $this->createPrice($productId, $plan->getMonthlyPriceCents(), 'month');
            $plan->setStripePriceIdMonthly($price->id);
        }

        // Annual
        if ($plan->getAnnualPriceCents() > 0) {
            if ($plan->getStripePriceIdAnnual() !== null) {
                $this->archivePrice($plan->getStripePriceIdAnnual());
            }
            $price = $this->createPrice($productId, $plan->getAnnualPriceCents(), 'year');
            $plan->setStripePriceIdAnnual($price->id);
        }
    }
}
