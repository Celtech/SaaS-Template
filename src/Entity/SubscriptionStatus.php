<?php

declare(strict_types=1);

namespace App\Entity;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Paused = 'paused';

    public function isAccessible(): bool
    {
        return match ($this) {
            self::Trialing, self::Active => true,
            default => false,
        };
    }

    public function requiresPaymentUpdate(): bool
    {
        return match ($this) {
            self::PastDue, self::Unpaid => true,
            default => false,
        };
    }
}
