<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\OrgInviteType;
use App\Repository\OrgInvitationRepository;
use App\Service\OrgInvitationService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
final class OrgInviteController extends AbstractController
{
    public function __construct(
        private readonly OrgInvitationService $invitationService,
        private readonly OrgInvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/org/invite', name: 'org_invite', methods: ['GET', 'POST'])]
    public function invite(Request $request): Response
    {
        $this->denyAccessUnlessGranted('org.members.invite');

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(OrgInviteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();
            $email = $data['email'];

            try {
                $this->invitationService->sendInvite($org, $email, $user);
                $this->addFlash('success', \sprintf('Invitation sent to %s.', $email));

                return $this->redirectToRoute('org_settings');
            } catch (InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('org/invite.html.twig', [
            'form' => $form,
            'org' => $org,
        ]);
    }

    #[Route('/org/invitations/{id}/revoke', name: 'org_invitation_revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('org.members.invite');

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('org_invitation_revoke_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('org_settings');
        }

        $invitation = $this->invitationRepository->find(Uuid::fromString($id));

        if ($invitation === null || !$invitation->getOrganization()->getId()->equals($org->getId())) {
            throw $this->createNotFoundException();
        }

        if (!$invitation->isPending()) {
            $this->addFlash('error', 'This invitation can no longer be revoked.');

            return $this->redirectToRoute('org_settings');
        }

        $this->em->remove($invitation);
        $this->em->flush();

        $this->addFlash('success', \sprintf('Invitation to %s has been revoked.', $invitation->getEmail()));

        return $this->redirectToRoute('org_settings');
    }
}
