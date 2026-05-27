<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\TrialExpiryBehavior;
use App\Message\Billing\ProcessExpiredTrialsMessage;
use App\Repository\BillingSettingsRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\EntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessExpiredTrialsHandler
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly BillingSettingsRepository $billingSettingsRepository,
        private readonly PlanRepository $planRepository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        private readonly EntitlementService $entitlementService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessExpiredTrialsMessage $message): void
    {
        $settings = $this->billingSettingsRepository->getSettings();
        $expiredTrials = $this->subscriptionRepository->findExpiredTrials();

        if ($expiredTrials === []) {
            return;
        }

        $behavior = $settings->getTrialExpiryBehavior();

        foreach ($expiredTrials as $subscription) {
            $this->applyBehavior($subscription, $behavior);
        }

        $this->em->flush();

        foreach ($expiredTrials as $subscription) {
            $this->entitlementService->invalidateForOrg($subscription->getOrganization());
        }
    }

    private function applyBehavior(Subscription $subscription, TrialExpiryBehavior $behavior): void
    {
        $orgId = $subscription->getOrganization()->getId()->toRfc4122();
        $oldStatus = $subscription->getStatus()->value;

        match ($behavior) {
            TrialExpiryBehavior::RequirePayment => $this->requirePayment($subscription),
            TrialExpiryBehavior::DowngradeToFree => $this->downgradeToFree($subscription),
            TrialExpiryBehavior::Cancel => $this->cancel($subscription),
        };

        $this->auditLogger->logBillingEvent(
            'trial.expired',
            $orgId,
            'organization',
            ['status' => $oldStatus],
            ['status' => $subscription->getStatus()->value, 'behavior' => $behavior->value],
        );

        $this->logger->info('Trial expired', [
            'organization_id' => $orgId,
            'behavior' => $behavior->value,
            'new_status' => $subscription->getStatus()->value,
        ]);
    }

    private function requirePayment(Subscription $subscription): void
    {
        $subscription->setStatus(SubscriptionStatus::Unpaid);
    }

    private function downgradeToFree(Subscription $subscription): void
    {
        $freePlan = $this->planRepository->findFreePlan();

        if ($freePlan === null) {
            // No free plan configured — fall back to requiring payment
            $this->logger->warning('DowngradeToFree behavior configured but no free plan exists; falling back to RequirePayment', [
                'organization_id' => $subscription->getOrganization()->getId()->toRfc4122(),
            ]);
            $this->requirePayment($subscription);

            return;
        }

        $subscription->setPlan($freePlan);
        $subscription->setStatus(SubscriptionStatus::Active);
        // Stripe subscription (if any) is intentionally left in place;
        // it will be reconciled when Stripe sends customer.subscription.deleted
        // or when the admin cancels it from the admin backend.
    }

    private function cancel(Subscription $subscription): void
    {
        $subscription->setStatus(SubscriptionStatus::Canceled);
    }
}
