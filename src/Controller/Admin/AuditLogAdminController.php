<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/audit-log')]
final class AuditLogAdminController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('', name: 'admin_audit_log_index', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $auditLogRepository): Response
    {
        $action = $request->query->getString('action') ?: null;
        $actorId = $request->query->getString('actor') ?: null;
        $sessionId = $request->query->getString('session') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $entries = $auditLogRepository->findFiltered($action, $actorId, $sessionId, $page, self::PER_PAGE);
        $total = $auditLogRepository->countFiltered($action, $actorId, $sessionId);

        return $this->render('admin/audit_log/index.html.twig', [
            'entries' => $entries,
            'action' => $action ?? '',
            'actor' => $actorId ?? '',
            'session' => $sessionId ?? '',
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }
}
