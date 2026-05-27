<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Repository\AuditLogRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/orgs')]
final class OrgAdminController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('', name: 'admin_orgs_index', methods: ['GET'])]
    public function index(Request $request, OrganizationRepository $orgRepository): Response
    {
        $search = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $orgs = $orgRepository->findWithSearch($search, $page, self::PER_PAGE);
        $total = $orgRepository->countWithSearch($search);

        return $this->render('admin/orgs/index.html.twig', [
            'orgs' => $orgs,
            'search' => $search ?? '',
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    #[Route('/{id}', name: 'admin_orgs_show', methods: ['GET'])]
    public function show(
        Organization $org,
        UserRepository $userRepository,
        SubscriptionRepository $subscriptionRepository,
        AuditLogRepository $auditLogRepository,
    ): Response {
        $members = $userRepository->findByOrganization($org);
        $subscription = $subscriptionRepository->findForOrg($org);
        $auditLogs = $auditLogRepository->findBySubject($org->getId()->toRfc4122(), 'organization', 25);

        return $this->render('admin/orgs/show.html.twig', [
            'org' => $org,
            'members' => $members,
            'subscription' => $subscription,
            'auditLogs' => $auditLogs,
        ]);
    }
}
