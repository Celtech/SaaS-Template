<?php

declare(strict_types=1);

namespace App\Service\Stripe;

use App\Entity\Plan;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

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
        return $this->stripe->products->create([
            'name' => $name,
            'description' => $description,
            'type' => 'service',
        ]);
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
     * Creates or replaces both recurring prices for a plan (monthly + annual).
     * Archives the old prices if they exist on the Plan entity.
     * Updates $plan's Stripe price IDs in place — caller must flush the entity.
     *
     * @throws ApiErrorException
     */
    public function syncPlanPrices(Plan $plan): void
    {
        if ($plan->getStripeProductId() === null) {
            return;
        }

        // Monthly
        if ($plan->getMonthlyPriceCents() > 0) {
            if ($plan->getStripePriceIdMonthly() !== null) {
                $this->archivePrice($plan->getStripePriceIdMonthly());
            }
            $price = $this->createPrice($plan->getStripeProductId(), $plan->getMonthlyPriceCents(), 'month');
            $plan->setStripePriceIdMonthly($price->id);
        }

        // Annual
        if ($plan->getAnnualPriceCents() > 0) {
            if ($plan->getStripePriceIdAnnual() !== null) {
                $this->archivePrice($plan->getStripePriceIdAnnual());
            }
            $price = $this->createPrice($plan->getStripeProductId(), $plan->getAnnualPriceCents(), 'year');
            $plan->setStripePriceIdAnnual($price->id);
        }
    }
}
