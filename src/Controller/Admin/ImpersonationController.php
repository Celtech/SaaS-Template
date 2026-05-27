<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/impersonate')]
final class ImpersonationController extends AbstractController
{
    #[Route('/{id}', name: 'admin_impersonate_confirm', methods: ['GET', 'POST'])]
    public function confirm(User $user, Request $request): Response
    {
        if ($user->isDeleted() || $user->isSuspended()) {
            $this->addFlash('error', 'Cannot impersonate a suspended or deleted user.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        if ($user->getRoles() !== [] && \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $this->addFlash('error', 'Cannot impersonate another administrator.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('impersonate_' . $user->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');

                return $this->redirectToRoute('admin_impersonate_confirm', ['id' => $user->getId()]);
            }

            $reason = trim($request->request->getString('reason'));

            if ($reason === '') {
                $this->addFlash('error', 'A justification reason is required.');

                return $this->redirectToRoute('admin_impersonate_confirm', ['id' => $user->getId()]);
            }

            /** @var User $admin */
            $admin = $this->getUser();

            $request->getSession()->set('_impersonation', [
                'session_id' => Uuid::v4()->toRfc4122(),
                'reason' => $reason,
                'admin_id' => $admin->getId()->toRfc4122(),
                'target_user_id' => $user->getId()->toRfc4122(),
                'target_user_email' => $user->getEmail(),
            ]);

            return $this->redirect('/?_switch_user=' . urlencode($user->getEmail()));
        }

        return $this->render('admin/impersonate/confirm.html.twig', [
            'targetUser' => $user,
        ]);
    }
}
