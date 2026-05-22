<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\DataErasureRequest;
use App\Entity\User;
use App\Entity\UserSession;
use App\Form\ChangePasswordForm;
use App\Message\User\AnonymizeUserMessage;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class SecurityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserSessionRepository $sessionRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/security', name: 'profile_security')]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $activeSessions = $this->sessionRepository->findActiveByUser($user);
        $currentTokenHash = hash('sha256', $request->getSession()->getId());

        $changePasswordForm = $this->createForm(ChangePasswordForm::class, null, [
            'action' => $this->generateUrl('profile_change_password'),
        ]);

        return $this->render('profile/security.html.twig', [
            'user' => $user,
            'activeSessions' => $activeSessions,
            'currentTokenHash' => $currentTokenHash,
            'changePasswordForm' => $changePasswordForm,
        ]);
    }

    #[Route('/sessions/{id}/revoke', name: 'profile_session_revoke', methods: ['POST'])]
    public function revokeSession(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        $session = $this->em->find(UserSession::class, Uuid::fromString($id));

        if ($session === null || !$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('ROLE_USER');

        $session->revoke();
        $this->em->flush();

        $this->addFlash('success', 'Session revoked.');

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/sessions/revoke-all', name: 'profile_sessions_revoke_all', methods: ['POST'])]
    public function revokeAllSessions(Request $request, #[CurrentUser] User $user): Response
    {
        $currentTokenHash = hash('sha256', $request->getSession()->getId());
        $this->sessionRepository->revokeAllForUser($user, $currentTokenHash);

        $this->addFlash('success', 'All other sessions have been revoked.');

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/request-data-erasure', name: 'profile_request_erasure', methods: ['POST'])]
    public function requestErasure(#[CurrentUser] User $user): Response
    {
        $erasureRequest = new DataErasureRequest($user, 'User-initiated erasure request');
        $this->em->persist($erasureRequest);
        $this->em->flush();

        $this->bus->dispatch(new AnonymizeUserMessage($erasureRequest->getId()->toRfc4122()));

        $this->addFlash('success', 'Your data erasure request has been submitted and will be processed shortly.');

        return $this->redirectToRoute('auth_logout');
    }
}
