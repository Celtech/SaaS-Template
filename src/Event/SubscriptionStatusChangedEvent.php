<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;

/**
 * Dispatched whenever a subscription's status changes (via Stripe webhook or
 * scheduler). Background jobs and listeners hook into this to pause or resume
 * org-specific work — e.g. stop monitoring checks, disable scheduled exports.
 */
final class SubscriptionStatusChangedEvent
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly SubscriptionStatus $previousStatus,
        private readonly SubscriptionStatus $newStatus,
    ) {
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getPreviousStatus(): SubscriptionStatus
    {
        return $this->previousStatus;
    }

    public function getNewStatus(): SubscriptionStatus
    {
        return $this->newStatus;
    }

    public function wasAccessible(): bool
    {
        return $this->previousStatus->isAccessible();
    }

    public function isNowAccessible(): bool
    {
        return $this->newStatus->isAccessible();
    }

    /** True when the org just lost access (e.g. payment failed, canceled). */
    public function isAccessLost(): bool
    {
        return $this->wasAccessible() && !$this->isNowAccessible();
    }

    /** True when the org just regained access (e.g. payment recovered). */
    public function isAccessRestored(): bool
    {
        return !$this->wasAccessible() && $this->isNowAccessible();
    }
}
