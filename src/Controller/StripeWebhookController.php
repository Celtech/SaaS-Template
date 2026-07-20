<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\Permission;
use App\Enum\NotificationType;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRoleRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Billing\SubscriptionManager;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Stripe\StripeService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use UnexpectedValueException;

#[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PlanRepository $planRepository,
        private readonly StripeService $stripeService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly UserRoleRepository $userRoles,
        private readonly NotificationDispatcher $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Target('cache.stripe_webhooks')]
        private readonly CacheItemPoolInterface $eventCache,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
        } catch (SignatureVerificationException) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        } catch (UnexpectedValueException) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        // Idempotency: skip events already processed within Stripe's retry window (7-day TTL).
        $cacheKey = 'stripe_event_' . strtr($event->id, ['-' => '_', '.' => '_']);
        $cacheItem = $this->eventCache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            return new Response('OK', Response::HTTP_OK);
        }

        match ($event->type) {
            Event::CUSTOMER_SUBSCRIPTION_UPDATED => $this->handleSubscriptionUpdated($event),
            Event::CUSTOMER_SUBSCRIPTION_DELETED => $this->handleSubscriptionDeleted($event),
            Event::INVOICE_PAYMENT_SUCCEEDED => $this->handleInvoicePaymentSucceeded($event),
            Event::INVOICE_PAYMENT_FAILED => $this->handleInvoicePaymentFailed($event),
            default => null,
        };

        $cacheItem->set(true);
        $this->eventCache->save($cacheItem);

        return new Response('OK', Response::HTTP_OK);
    }

    private function handleSubscriptionUpdated(Event $event): void
    {
        $stripeSubscription = $event->data->object;
        if (!$stripeSubscription instanceof StripeSubscription) {
            return;
        }

        $subscription = $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscription->id);
        if ($subscription === null) {
            $this->logger->warning('stripe.webhook: subscription.updated for unknown subscription', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            $this->auditLogger->logBillingEvent(
                'subscription.updated.unmatched',
                $stripeSubscription->id,
                'stripe_subscription',
                null,
                ['event_id' => $event->id, 'status' => $stripeSubscription->status],
            );

            return;
        }

        // Fetch a fresh copy with latest_invoice expanded so SubscriptionManager can
        // derive currentPeriodEnd on Stripe API versions that omit current_period_end.
        try {
            $stripeSubscription = $this->stripeService->retrieveSubscription($stripeSubscription->id);
        } catch (ApiErrorException $e) {
            $this->logger->warning('stripe.webhook: could not re-fetch subscription', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'error' => $e->getMessage(),
            ]);
        }

        $planId = $stripeSubscription->metadata['plan_id'] ?? null;
        $plan = $planId !== null ? $this->planRepository->find($planId) : null;
        $plan ??= $subscription->getPlan();

        $stripeCustomerId = \is_string($stripeSubscription->customer)
            ? $stripeSubscription->customer
            : ($stripeSubscription->customer->id ?? $subscription->getStripeCustomerId() ?? '');

        $this->subscriptionManager->syncFromStripeSubscription(
            $subscription->getOrganization(),
            $plan,
            $stripeSubscription,
            $stripeCustomerId,
        );
    }

    private function handleSubscriptionDeleted(Event $event): void
    {
        $stripeSubscription = $event->data->object;
        if (!$stripeSubscription instanceof StripeSubscription) {
            return;
        }

        $subscription = $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscription->id);
        if ($subscription === null) {
            $this->logger->warning('stripe.webhook: subscription.deleted for unknown subscription', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            $this->auditLogger->logBillingEvent(
                'subscription.deleted.unmatched',
                $stripeSubscription->id,
                'stripe_subscription',
                null,
                ['event_id' => $event->id],
            );

            return;
        }

        $stripeCustomerId = \is_string($stripeSubscription->customer)
            ? $stripeSubscription->customer
            : ($stripeSubscription->customer->id ?? $subscription->getStripeCustomerId() ?? '');

        $this->subscriptionManager->syncFromStripeSubscription(
            $subscription->getOrganization(),
            $subscription->getPlan(),
            $stripeSubscription,
            $stripeCustomerId,
        );

        $this->auditLogger->logBillingEvent(
            'subscription.canceled',
            $subscription->getOrganization()->getId()->toRfc4122(),
            'organization',
            ['status' => $subscription->getStatus()->value],
            ['stripe_subscription_id' => $stripeSubscription->id],
        );

        $this->notifyBillingAdmins(
            $subscription->getOrganization(),
            NotificationType::BillingSubscriptionCancelled,
            'Subscription cancelled',
            'Your subscription has been cancelled.',
        );
    }

    private function handleInvoicePaymentSucceeded(Event $event): void
    {
        $invoice = $event->data->object;
        if (!$invoice instanceof Invoice) {
            return;
        }

        $stripeSubscriptionId = $this->extractSubscriptionId($invoice);
        if ($stripeSubscriptionId === null) {
            return;
        }

        $subscription = $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscriptionId);
        if ($subscription === null) {
            return;
        }

        $this->auditLogger->logBillingEvent(
            'invoice.paid',
            $subscription->getOrganization()->getId()->toRfc4122(),
            'organization',
            null,
            [
                'amount' => $invoice->amount_paid,
                'currency' => $invoice->currency,
                'invoice_id' => $invoice->id,
            ],
        );
    }

    private function handleInvoicePaymentFailed(Event $event): void
    {
        $invoice = $event->data->object;
        if (!$invoice instanceof Invoice) {
            return;
        }

        $stripeSubscriptionId = $this->extractSubscriptionId($invoice);
        if ($stripeSubscriptionId === null) {
            return;
        }

        $subscription = $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscriptionId);
        if ($subscription === null) {
            return;
        }

        $this->auditLogger->logBillingEvent(
            'invoice.payment_failed',
            $subscription->getOrganization()->getId()->toRfc4122(),
            'organization',
            null,
            [
                'amount' => $invoice->amount_due,
                'currency' => $invoice->currency,
                'invoice_id' => $invoice->id,
                'attempt_count' => $invoice->attempt_count,
            ],
        );

        $this->logger->warning('stripe.webhook: invoice payment failed', [
            'org_id' => $subscription->getOrganization()->getId()->toRfc4122(),
            'invoice_id' => $invoice->id,
            'attempt_count' => $invoice->attempt_count,
        ]);

        $this->notifyBillingAdmins(
            $subscription->getOrganization(),
            NotificationType::BillingPaymentFailed,
            'Payment failed',
            'Your invoice payment failed. Please update your billing details.',
        );
    }

    private function notifyBillingAdmins(Organization $organization, NotificationType $type, string $title, string $body): void
    {
        $actionUrl = $this->urlGenerator->generate('org_settings_billing', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($this->userRoles->findUsersWithPermissionInOrg($organization->getId(), Permission::OrgBillingManage) as $user) {
            $this->notifications->dispatch($user, $type, $title, $body, $actionUrl);
        }
    }

    /**
     * In Stripe PHP SDK v20, the subscription ID moved from Invoice::$subscription
     * to Invoice::$parent->subscription_details->subscription.
     */
    private function extractSubscriptionId(Invoice $invoice): ?string
    {
        $raw = $invoice->parent?->subscription_details->subscription ?? null;

        if (\is_string($raw) && $raw !== '') {
            return $raw;
        }

        return null;
    }
}
