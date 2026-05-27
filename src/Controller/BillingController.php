<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Repository\BillingSettingsRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Billing\SubscriptionManager;
use App\Service\Stripe\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/billing')]
final class BillingController extends AbstractController
{
    #[Route('/plans', name: 'billing_plans', methods: ['GET'])]
    public function plans(
        Request $request,
        PlanRepository $planRepository,
        SubscriptionRepository $subscriptionRepository,
        BillingSettingsRepository $billingSettingsRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        $pendingPlan = $request->getSession()->get('billing.pending_plan');

        return $this->render('billing/plans.html.twig', [
            'plans' => $planRepository->findAllActive(),
            'currentSubscription' => $org !== null ? $subscriptionRepository->findForOrg($org) : null,
            'billingSettings' => $billingSettingsRepository->getSettings(),
            'pendingPlan' => $pendingPlan,
        ]);
    }

    #[Route('/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(
        Request $request,
        PlanRepository $planRepository,
        SubscriptionRepository $subscriptionRepository,
        BillingSettingsRepository $billingSettingsRepository,
        StripeService $stripeService,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('billing_checkout', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        $planId = $request->getPayload()->getString('plan_id');
        $interval = $request->getPayload()->getString('interval');

        if (!\in_array($interval, ['month', 'year'], true)) {
            $this->addFlash('error', 'Invalid billing interval.');

            return $this->redirectToRoute('billing_plans');
        }

        try {
            $plan = $planRepository->find(Uuid::fromString($planId));
        } catch (InvalidArgumentException) {
            $plan = null;
        }

        if ($plan === null || !$plan->isActive()) {
            $this->addFlash('error', 'Plan not found.');

            return $this->redirectToRoute('billing_plans');
        }

        if ($plan->isFree()) {
            $this->addFlash('error', 'Free plans do not require a payment method.');

            return $this->redirectToRoute('billing_plans');
        }

        $stripePriceId = $interval === 'year'
            ? $plan->getStripePriceIdAnnual()
            : $plan->getStripePriceIdMonthly();

        if ($stripePriceId === null) {
            $this->addFlash('error', 'This plan is not available for the selected billing interval.');

            return $this->redirectToRoute('billing_plans');
        }

        try {
            // Reuse existing customer if the org already has one
            $existingSubscription = $subscriptionRepository->findForOrg($org);
            $stripeCustomerId = $existingSubscription?->getStripeCustomerId();

            if ($stripeCustomerId === null) {
                $customer = $stripeService->createCustomer(
                    $user->getEmail(),
                    $org->getName(),
                    $org->getId()->toRfc4122(),
                );
                $stripeCustomerId = $customer->id;
            }

            $billingSettings = $billingSettingsRepository->getSettings();
            $trialDays = $billingSettings->isTrialEnabled() ? $billingSettings->getTrialDays() : null;

            $successUrl = $this->generateUrl(
                'billing_success',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ) . '?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $this->generateUrl('billing_plans', [], UrlGeneratorInterface::ABSOLUTE_URL);

            $session = $stripeService->createCheckoutSession(
                $stripeCustomerId,
                $stripePriceId,
                $plan->getId()->toRfc4122(),
                $org->getId()->toRfc4122(),
                $successUrl,
                $cancelUrl,
                $trialDays,
            );

            $auditLogger->logBillingEvent(
                'checkout.initiated',
                $org->getId()->toRfc4122(),
                'organization',
                null,
                ['plan' => $plan->getSlug(), 'interval' => $interval],
                $user->getId()->toRfc4122(),
            );
        } catch (ApiErrorException $e) {
            $this->addFlash('error', 'Could not start checkout: ' . $e->getMessage());

            return $this->redirectToRoute('billing_plans');
        }

        return $this->redirect($session->url ?? $this->generateUrl('billing_plans'));
    }

    #[Route('/success', name: 'billing_success', methods: ['GET'])]
    public function success(
        Request $request,
        PlanRepository $planRepository,
        StripeService $stripeService,
        SubscriptionManager $subscriptionManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        $sessionId = $request->query->getString('session_id');
        if ($sessionId === '') {
            return $this->redirectToRoute('billing_plans');
        }

        try {
            $session = $stripeService->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException) {
            $this->addFlash('error', 'Could not retrieve checkout session.');

            return $this->redirectToRoute('billing_plans');
        }

        if ($session->status !== 'complete') {
            return $this->redirectToRoute('billing_plans');
        }

        $planId = $session->metadata['plan_id'] ?? null;
        if ($planId === null) {
            $this->addFlash('error', 'Could not determine the selected plan.');

            return $this->redirectToRoute('billing_plans');
        }

        try {
            $plan = $planRepository->find(Uuid::fromString((string) $planId));
        } catch (InvalidArgumentException) {
            $plan = null;
        }

        if ($plan === null) {
            $this->addFlash('error', 'Plan not found.');

            return $this->redirectToRoute('billing_plans');
        }

        $stripeSubscription = $session->subscription;
        if (!$stripeSubscription instanceof StripeSubscription) {
            $this->addFlash('error', 'Subscription data missing from checkout session.');

            return $this->redirectToRoute('billing_plans');
        }

        $stripeCustomerId = \is_string($session->customer) ? $session->customer : ($session->customer->id ?? '');

        $subscriptionManager->syncFromStripeSubscription(
            $org,
            $plan,
            $stripeSubscription,
            $stripeCustomerId,
        );

        $request->getSession()->remove('billing.pending_plan');
        $this->addFlash('success', 'Your subscription is now active. Welcome!');

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/select-free', name: 'billing_select_free', methods: ['POST'])]
    public function selectFree(
        Request $request,
        PlanRepository $planRepository,
        SubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('billing_select_free', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        $freePlan = $planRepository->findOneBy(['isFree' => true, 'isActive' => true]);
        if ($freePlan === null) {
            $this->addFlash('error', 'Free plan not found.');

            return $this->redirectToRoute('billing_plans');
        }

        $existing = $subscriptionRepository->findForOrg($org);
        if ($existing !== null) {
            $this->addFlash('info', 'You already have an active subscription.');

            return $this->redirectToRoute('app_dashboard');
        }

        $subscription = new Subscription($org, $freePlan, SubscriptionStatus::Active);
        $em->persist($subscription);
        $em->flush();

        $auditLogger->logBillingEvent(
            'subscription.created',
            $org->getId()->toRfc4122(),
            'organization',
            null,
            ['status' => SubscriptionStatus::Active->value, 'plan' => $freePlan->getSlug()],
            $user->getId()->toRfc4122(),
        );

        $request->getSession()->remove('billing.pending_plan');
        $this->addFlash('success', 'Welcome! You\'re on the free plan.');

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/cancel', name: 'billing_cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        $this->addFlash('info', 'Checkout cancelled. You can choose a plan whenever you\'re ready.');

        return $this->redirectToRoute('billing_plans');
    }

    #[Route('/reactivate', name: 'billing_reactivate', methods: ['GET'])]
    public function reactivate(SubscriptionRepository $subscriptionRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        $subscription = $org !== null ? $subscriptionRepository->findForOrg($org) : null;

        return $this->render('billing/reactivate.html.twig', [
            'subscription' => $subscription,
        ]);
    }

    #[Route('/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(
        Request $request,
        SubscriptionRepository $subscriptionRepository,
        StripeService $stripeService,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('billing_portal', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        $subscription = $subscriptionRepository->findForOrg($org);
        $stripeCustomerId = $subscription?->getStripeCustomerId();

        if ($stripeCustomerId === null) {
            $this->addFlash('error', 'No billing account found. Please contact support.');

            return $this->redirectToRoute('billing_reactivate');
        }

        try {
            $returnUrl = $this->generateUrl('billing_reactivate', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $portalSession = $stripeService->createPortalSession($stripeCustomerId, $returnUrl);

            $auditLogger->logBillingEvent(
                'portal.accessed',
                $org->getId()->toRfc4122(),
                'organization',
                null,
                null,
                $user->getId()->toRfc4122(),
            );
        } catch (ApiErrorException $e) {
            $this->addFlash('error', 'Could not open billing portal: ' . $e->getMessage());

            return $this->redirectToRoute('billing_reactivate');
        }

        return $this->redirect($portalSession->url);
    }
}
