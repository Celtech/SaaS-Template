<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Entity\Permission;
use App\Enum\NotificationType;
use App\Message\Billing\NotifyTrialsExpiringSoonMessage;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRoleRepository;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Runs daily; catches each trial in a single 24h-wide window 3 days out, so each trial is only ever notified once. */
#[AsMessageHandler]
final class NotifyTrialsExpiringSoonHandler
{
    private const DAYS_BEFORE_EXPIRY = 3;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly UserRoleRepository $userRoles,
        private readonly NotificationDispatcher $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(NotifyTrialsExpiringSoonMessage $message): void
    {
        $trials = $this->subscriptions->findTrialsEndingBetween(self::DAYS_BEFORE_EXPIRY, self::DAYS_BEFORE_EXPIRY + 1);

        if ($trials === []) {
            return;
        }

        $actionUrl = $this->urlGenerator->generate('org_settings_billing', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($trials as $subscription) {
            $organization = $subscription->getOrganization();

            foreach ($this->userRoles->findUsersWithPermissionInOrg($organization->getId(), Permission::OrgBillingManage) as $admin) {
                $this->notifications->dispatch(
                    $admin,
                    NotificationType::BillingTrialExpiring,
                    'Your trial is ending soon',
                    \sprintf('Your trial ends in %d days. Add a payment method to keep your subscription active.', self::DAYS_BEFORE_EXPIRY),
                    $actionUrl,
                );
            }
        }
    }
}
