<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Polled every 30s by a small Stimulus controller — kept cheap: a single COUNT query. */
    #[Route('/bell', name: 'notifications_bell', methods: ['GET'])]
    public function bell(#[CurrentUser] User $user): Response
    {
        return $this->render('notifications/_bell.html.twig', [
            'unreadCount' => $this->notifications->countUnreadInApp($user),
        ]);
    }

    /** Lazy-loaded turbo-frame when the bell is clicked. */
    #[Route('/dropdown', name: 'notifications_dropdown', methods: ['GET'])]
    public function dropdown(#[CurrentUser] User $user): Response
    {
        return $this->render('notifications/_dropdown.html.twig', [
            'notifications' => $this->notifications->findInAppForUser($user, limit: 10),
        ]);
    }

    #[Route('', name: 'notifications_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $type = $request->query->getString('type') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        if ($type !== null && NotificationType::tryFrom($type) === null) {
            $type = null;
        }

        $total = $this->notifications->countInAppForUser($user, $type);

        return $this->render('notifications/index.html.twig', [
            'notifications' => $this->notifications->findInAppForUser($user, $type, self::PER_PAGE, self::PER_PAGE * ($page - 1)),
            'types' => NotificationType::cases(),
            'type' => $type,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    #[Route('/{id}/read', name: 'notifications_mark_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessOwnedByUser($notification, $user);

        if (!$this->isCsrfTokenValid('notifications_mark_read_' . $notification->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $notification->markAsRead();
        $this->em->flush();

        return $this->redirectAfterAction($request);
    }

    #[Route('/read-all', name: 'notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('notifications_mark_all_read', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->notifications->markAllAsReadForUser($user);

        return $this->redirectAfterAction($request);
    }

    private function denyAccessUnlessOwnedByUser(Notification $notification, User $user): void
    {
        if (!$notification->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }
    }

    /** `redirect_to` is set explicitly by the calling template — the dropdown (a lazy turbo-frame) and the full feed page need different targets. */
    private function redirectAfterAction(Request $request): Response
    {
        if ($request->request->getString('redirect_to') === 'dropdown') {
            return $this->redirectToRoute('notifications_dropdown');
        }

        return $this->redirectToRoute('notifications_index');
    }
}
