<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SubscriptionStatus;
use App\Repository\OrganizationRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin')]
final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'userCount' => $this->userRepository->countWithSearch(null),
            'organizationCount' => $this->organizationRepository->countWithSearch(null),
            'activeSubscriptionCount' => $this->subscriptionRepository->countByStatus(SubscriptionStatus::Active),
        ]);
    }
}
