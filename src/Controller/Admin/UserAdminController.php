<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/users')]
final class UserAdminController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('', name: 'admin_users_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $search = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $users = $userRepository->findWithSearch($search, $page, self::PER_PAGE);
        $total = $userRepository->countWithSearch($search);

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'search' => $search ?? '',
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    #[Route('/{id}', name: 'admin_users_show', methods: ['GET'])]
    public function show(
        User $user,
        SubscriptionRepository $subscriptionRepository,
        AuditLogRepository $auditLogRepository,
    ): Response {
        $subscription = $user->getOrganization() !== null
            ? $subscriptionRepository->findForOrg($user->getOrganization())
            : null;

        $auditLogs = $auditLogRepository->findByActor($user->getId()->toRfc4122(), 25);

        return $this->render('admin/users/show.html.twig', [
            'user' => $user,
            'subscription' => $subscription,
            'auditLogs' => $auditLogs,
        ]);
    }

    #[Route('/{id}/suspend', name: 'admin_users_suspend', methods: ['POST'])]
    public function suspend(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('user_suspend_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        if ($user->isSuspended()) {
            $this->addFlash('error', 'User is already suspended.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $user->suspend();
        $em->flush();

        /** @var User $admin */
        $admin = $this->getUser();
        $auditLogger->logAdminAction(
            'user.suspended',
            $admin->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            'user',
            ['suspended' => false],
            ['suspended' => true],
        );

        $this->addFlash('success', \sprintf('User %s has been suspended.', $user->getEmail()));

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/unsuspend', name: 'admin_users_unsuspend', methods: ['POST'])]
    public function unsuspend(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('user_unsuspend_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        if (!$user->isSuspended()) {
            $this->addFlash('error', 'User is not suspended.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $user->unsuspend();
        $em->flush();

        /** @var User $admin */
        $admin = $this->getUser();
        $auditLogger->logAdminAction(
            'user.unsuspended',
            $admin->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            'user',
            ['suspended' => true],
            ['suspended' => false],
        );

        $this->addFlash('success', \sprintf('User %s has been unsuspended.', $user->getEmail()));

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }
}
