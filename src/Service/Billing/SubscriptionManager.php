<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Organization;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Subscription as StripeSubscription;

/**
 * Creates and synchronises local Subscription entities from Stripe subscription objects.
 *
 * Used by:
 *   - BillingController::success()  after a completed Checkout Session
 *   - StripeWebhookController       for async status updates (PR 5)
 */
final class SubscriptionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Creates or updates the local Subscription entity to match the given Stripe subscription.
     *
     * Pass $stripeCustomerId explicitly because it is not always present on the Stripe object
     * when called from a webhook (the customer field may be an ID string).
     */
    public function syncFromStripeSubscription(
        Organization $org,
        Plan $plan,
        StripeSubscription $stripeSubscription,
        string $stripeCustomerId,
    ): Subscription {
        $subscription = $this->subscriptionRepository->findForOrg($org);
        $isNew = $subscription === null;

        if ($isNew) {
            $subscription = new Subscription($org, $plan, $this->mapStatus($stripeSubscription->status));
        }

        $previousStatus = $isNew ? null : $subscription->getStatus();

        $subscription->setPlan($plan);
        $subscription->setStatus($this->mapStatus($stripeSubscription->status));
        $subscription->setStripeSubscriptionId($stripeSubscription->id);
        $subscription->setStripeCustomerId($stripeCustomerId);

        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $subscription->setStripePriceId($priceId);

        $subscription->setCurrentPeriodStart(
            isset($stripeSubscription->current_period_start)
                ? new DateTimeImmutable()->setTimestamp((int) $stripeSubscription->current_period_start)
                : null,
        );
        $subscription->setCurrentPeriodEnd(
            isset($stripeSubscription->current_period_end)
                ? new DateTimeImmutable()->setTimestamp((int) $stripeSubscription->current_period_end)
                : null,
        );

        $subscription->setCancelAtPeriodEnd((bool) ($stripeSubscription->cancel_at_period_end ?? false));

        $subscription->setTrialEndsAt(
            isset($stripeSubscription->trial_end)
                ? new DateTimeImmutable()->setTimestamp((int) $stripeSubscription->trial_end)
                : null,
        );

        $subscription->setCanceledAt(
            isset($stripeSubscription->canceled_at)
                ? new DateTimeImmutable()->setTimestamp((int) $stripeSubscription->canceled_at)
                : null,
        );

        if ($isNew) {
            $this->em->persist($subscription);
        }

        $this->em->flush();

        $action = $isNew ? 'subscription.created' : 'subscription.updated';
        $this->auditLogger->logBillingEvent(
            $action,
            $org->getId()->toRfc4122(),
            'organization',
            $previousStatus !== null ? ['status' => $previousStatus->value] : null,
            ['status' => $subscription->getStatus()->value, 'plan' => $plan->getSlug()],
        );

        return $subscription;
    }

    private function mapStatus(string $stripeStatus): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'unpaid' => SubscriptionStatus::Unpaid,
            'canceled' => SubscriptionStatus::Canceled,
            'incomplete' => SubscriptionStatus::Incomplete,
            'incomplete_expired' => SubscriptionStatus::IncompleteExpired,
            'paused' => SubscriptionStatus::Paused,
            default => SubscriptionStatus::Incomplete,
        };
    }
}
