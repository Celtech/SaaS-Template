<?php

declare(strict_types=1);

namespace App\Enum;

/** Code-defined catalog of all outgoing webhook event types. */
enum WebhookEvent: string
{
    case OrgMemberInvited = 'org.member.invited';
    case OrgMemberJoined = 'org.member.joined';
    case OrgMemberRemoved = 'org.member.removed';
    case BillingSubscriptionCreated = 'billing.subscription.created';
    case BillingSubscriptionUpdated = 'billing.subscription.updated';
    case BillingSubscriptionCancelled = 'billing.subscription.cancelled';
    case BillingPaymentSucceeded = 'billing.payment.succeeded';
    case BillingPaymentFailed = 'billing.payment.failed';

    public function description(): string
    {
        return match ($this) {
            self::OrgMemberInvited => 'A new member was invited to the organization',
            self::OrgMemberJoined => 'An invited member joined the organization',
            self::OrgMemberRemoved => 'A member was removed from the organization',
            self::BillingSubscriptionCreated => 'A subscription was created',
            self::BillingSubscriptionUpdated => 'A subscription was updated',
            self::BillingSubscriptionCancelled => 'A subscription was cancelled',
            self::BillingPaymentSucceeded => 'An invoice payment succeeded',
            self::BillingPaymentFailed => 'An invoice payment failed',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @param string[] $values */
    public static function validSubset(array $values): bool
    {
        foreach ($values as $v) {
            if (self::tryFrom($v) === null) {
                return false;
            }
        }

        return true;
    }
}
