<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Entity\SubscriptionStatus;
use App\Message\Billing\EnforceGracePeriodMessage;
use App\Repository\BillingSettingsRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\EntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EnforceGracePeriodHandler
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly BillingSettingsRepository $billingSettingsRepository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        private readonly EntitlementService $entitlementService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnforceGracePeriodMessage $message): void
    {
        $settings = $this->billingSettingsRepository->getSettings();
        $pastGrace = $this->subscriptionRepository->findPastGracePeriod($settings->getGracePeriodDays());

        if ($pastGrace === []) {
            return;
        }

        foreach ($pastGrace as $subscription) {
            $orgId = $subscription->getOrganization()->getId()->toRfc4122();

            $subscription->setStatus(SubscriptionStatus::Canceled);

            $this->auditLogger->logBillingEvent(
                'subscription.grace_period_expired',
                $orgId,
                'organization',
                ['status' => SubscriptionStatus::PastDue->value],
                ['status' => SubscriptionStatus::Canceled->value],
            );

            $this->logger->info('Grace period expired — subscription canceled', [
                'organization_id' => $orgId,
                'grace_period_days' => $settings->getGracePeriodDays(),
            ]);
        }

        $this->em->flush();

        foreach ($pastGrace as $subscription) {
            $this->entitlementService->invalidateForOrg($subscription->getOrganization());
        }
    }
}
