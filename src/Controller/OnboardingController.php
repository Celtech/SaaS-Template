<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Form\OrgOnboardingType;
use App\Repository\BillingSettingsRepository;
use App\Repository\PlanRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Billing\PlanTokenService;
use App\Service\OrganizationService;
use App\Service\Stripe\StripeService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/onboarding')]
final class OnboardingController extends AbstractController
{
    #[Route('/org', name: 'onboarding_org', methods: ['GET', 'POST'])]
    public function org(
        Request $request,
        OrganizationService $organizationService,
        BillingSettingsRepository $billingSettingsRepository,
        PlanRepository $planRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
        StripeService $stripeService,
        PlanTokenService $planTokenService,
        #[Autowire(param: 'billing.enabled')]
        bool $billingEnabled,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getOrganization() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $defaultName = \sprintf("%s's Workspace", explode(' ', $user->getName())[0]);
        $form = $this->createForm(OrgOnboardingType::class, ['name' => $defaultName]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('onboarding/org.html.twig', ['form' => $form]);
        }

        /** @var array{name: string} $data */
        $data = $form->getData();
        $org = $organizationService->createForUser($user, $data['name']);

        if (!$billingEnabled) {
            return $this->redirectToRoute('app_dashboard');
        }

        $billingSettings = $billingSettingsRepository->getSettings();

        // Resolve plan and proration from session token
        $tokenRaw = $request->getSession()->get('billing.pending_plan');
        $tokenData = \is_string($tokenRaw) ? $planTokenService->validate($tokenRaw) : null;

        $plan = null;
        $interval = 'month';
        $trialDays = $billingSettings->isTrialEnabled() ? $billingSettings->getTrialDays() : 0;

        if ($tokenData !== null) {
            $plan = $planRepository->findBySlug($tokenData['plan']);
            $interval = $tokenData['interval'];

            // Prorate: the trial clock starts when the user clicked "Get started" on the marketing page
            if ($trialDays > 0) {
                $daysSinceIssued = (int) floor((time() - $tokenData['iat']) / 86400);
                $trialDays = max(0, $trialDays - $daysSinceIssued);
            }
        }

        // Fall back to the configured default trial plan when no token was provided
        if ($plan === null) {
            $defaultSlug = $billingSettings->getDefaultTrialPlanSlug();
            $plan = $defaultSlug !== null ? $planRepository->findBySlug($defaultSlug) : null;
        }

        // Fall back further to the free plan
        if ($plan === null) {
            $plan = $planRepository->findOneBy(['isFree' => true, 'isActive' => true]);
        }

        // No usable plan at all — go to dashboard and let SubscriptionAccessSubscriber handle it
        if ($plan === null) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Free plan — activate immediately, no trial or Stripe needed
        if ($plan->isFree()) {
            $subscription = new Subscription($org, $plan, SubscriptionStatus::Active);
            $em->persist($subscription);
            $em->flush();

            $auditLogger->logBillingEvent('subscription.created', $org->getId()->toRfc4122(), 'organization', null, [
                'status' => SubscriptionStatus::Active->value,
                'plan' => $plan->getSlug(),
            ], $user->getId()->toRfc4122());

            $request->getSession()->remove('billing.pending_plan');

            return $this->redirectToRoute('app_dashboard');
        }

        // Paid plan — CC required: redirect to Stripe Checkout
        if ($billingSettings->isRequireCreditCard()) {
            $stripePriceId = $interval === 'year'
                ? $plan->getStripePriceIdAnnual()
                : $plan->getStripePriceIdMonthly();

            if ($stripePriceId === null) {
                // Plan has no Stripe price configured — fall through to local trial
                return $this->startLocalTrial($request, $em, $auditLogger, $org, $user, $plan, $trialDays);
            }

            try {
                $customer = $stripeService->createCustomer(
                    $user->getEmail(),
                    $org->getName(),
                    $org->getId()->toRfc4122(),
                );

                $successUrl = $this->generateUrl('billing_success', ['session_id' => '{CHECKOUT_SESSION_ID}'], UrlGeneratorInterface::ABSOLUTE_URL);
                $cancelUrl = $this->generateUrl('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);

                $session = $stripeService->createCheckoutSession(
                    $customer->id,
                    $stripePriceId,
                    $plan->getId()->toRfc4122(),
                    $org->getId()->toRfc4122(),
                    $successUrl,
                    $cancelUrl,
                    $trialDays > 0 ? $trialDays : null,
                );

                $request->getSession()->remove('billing.pending_plan');

                return $this->redirect($session->url ?? $this->generateUrl('app_dashboard'));
            } catch (ApiErrorException) {
                // Stripe unavailable — fall through to local trial so the user isn't blocked
                return $this->startLocalTrial($request, $em, $auditLogger, $org, $user, $plan, $trialDays);
            }
        }

        // Paid plan — no CC required: create local trial subscription
        return $this->startLocalTrial($request, $em, $auditLogger, $org, $user, $plan, $trialDays);
    }

    private function startLocalTrial(
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
        \App\Entity\Organization $org,
        User $user,
        \App\Entity\Plan $plan,
        int $trialDays,
    ): Response {
        $status = $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;
        $subscription = new Subscription($org, $plan, $status);

        if ($trialDays > 0) {
            $subscription->setTrialEndsAt(new DateTimeImmutable("+{$trialDays} days"));
        }

        $em->persist($subscription);
        $em->flush();

        $auditLogger->logBillingEvent('subscription.created', $org->getId()->toRfc4122(), 'organization', null, [
            'status' => $status->value,
            'plan' => $plan->getSlug(),
            'trial_days' => $trialDays,
        ], $user->getId()->toRfc4122());

        $request->getSession()->remove('billing.pending_plan');

        return $this->redirectToRoute('app_dashboard');
    }
}
