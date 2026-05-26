<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\OrgOnboardingType;
use App\Service\OrganizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/onboarding')]
final class OnboardingController extends AbstractController
{
    #[Route('/org', name: 'onboarding_org', methods: ['GET', 'POST'])]
    public function org(
        Request $request,
        OrganizationService $organizationService,
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

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string} $data */
            $data = $form->getData();
            $organizationService->createForUser($user, $data['name']);

            if ($billingEnabled) {
                return $this->redirectToRoute('billing_plans');
            }

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('onboarding/org.html.twig', ['form' => $form]);
    }
}
