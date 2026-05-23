<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\BillingSettingsRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/billing')]
final class BillingController extends AbstractController
{
    #[Route('/plans', name: 'billing_plans', methods: ['GET'])]
    public function plans(
        PlanRepository $planRepository,
        SubscriptionRepository $subscriptionRepository,
        BillingSettingsRepository $billingSettingsRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        return $this->render('billing/plans.html.twig', [
            'plans' => $planRepository->findAllActive(),
            'currentSubscription' => $org !== null ? $subscriptionRepository->findForOrg($org) : null,
            'billingSettings' => $billingSettingsRepository->getSettings(),
        ]);
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
}
