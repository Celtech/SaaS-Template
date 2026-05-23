<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\OrgInviteType;
use App\Service\OrgInvitationService;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class OrgInviteController extends AbstractController
{
    public function __construct(
        private readonly OrgInvitationService $invitationService,
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
}
