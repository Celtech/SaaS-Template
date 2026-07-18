<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/webhooks')]
final class WebhookAdminController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('', name: 'admin_webhooks_index', methods: ['GET'])]
    public function index(Request $request, WebhookDeliveryRepository $deliveries): Response
    {
        $eventType = $request->query->getString('event') ?: null;
        $status = WebhookDeliveryStatus::tryFrom($request->query->getString('status'));
        $page = max(1, $request->query->getInt('page', 1));

        $entries = $deliveries->findFiltered($eventType, $status, $page, self::PER_PAGE);
        $total = $deliveries->countFiltered($eventType, $status);

        return $this->render('admin/webhooks/index.html.twig', [
            'entries' => $entries,
            'event' => $eventType ?? '',
            'status' => $status,
            'statuses' => WebhookDeliveryStatus::cases(),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }
}
