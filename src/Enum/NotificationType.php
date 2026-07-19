<?php

declare(strict_types=1);

namespace App\Enum;

/** Code-defined catalog of all in-app/email notification types. */
enum NotificationType: string
{
    case BillingPaymentFailed = 'billing.payment_failed';
    case BillingTrialExpiring = 'billing.trial_expiring';
    case BillingSubscriptionCancelled = 'billing.subscription_cancelled';
    case OrgMemberInvited = 'org.member_invited';
    case OrgMemberJoined = 'org.member_joined';
    case SecurityNewLogin = 'security.new_login';
    case SecuritySessionRevoked = 'security.session_revoked';

    public function description(): string
    {
        return match ($this) {
            self::BillingPaymentFailed => 'An invoice payment failed',
            self::BillingTrialExpiring => 'Your trial is ending soon',
            self::BillingSubscriptionCancelled => 'A subscription was cancelled',
            self::OrgMemberInvited => 'A new member was invited to the organization',
            self::OrgMemberJoined => 'An invited member joined the organization',
            self::SecurityNewLogin => 'A login from a new device or location',
            self::SecuritySessionRevoked => 'A session was remotely revoked',
        };
    }

    /** @return string[] channels this type can be configured for on the preferences page */
    public function supportedChannels(): array
    {
        return match ($this) {
            self::OrgMemberInvited, self::OrgMemberJoined => ['in_app'],
            default => ['in_app', 'email'],
        };
    }

    /** @return string[] channels enabled when no explicit NotificationPreference row exists */
    public function defaultChannels(): array
    {
        return match ($this) {
            // Opt-in and off by default: not everyone wants an email on every login.
            self::SecurityNewLogin => [],
            default => $this->supportedChannels(),
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
