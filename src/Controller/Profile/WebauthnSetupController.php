<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Service\Audit\AuditLogger;
use App\Service\WebauthnService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

#[Route('/profile/2fa/webauthn')]
#[IsGranted('ROLE_USER')]
class WebauthnSetupController extends AbstractController
{
    private const SESSION_OPTIONS_KEY = '_webauthn_registration_options';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebauthnService $webauthnService,
        private readonly WebauthnCredentialRepository $credentialRepo,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/register', name: 'profile_webauthn_register', methods: ['GET'])]
    public function register(#[CurrentUser] User $user, Request $request): Response
    {
        $existingIds = array_map(
            static fn (WebauthnCredential $c) => $c->getCredentialId(),
            $this->credentialRepo->findByUser($user),
        );

        $options = $this->webauthnService->createRegistrationOptions(
            userId: $user->getId()->toRfc4122(),
            userEmail: $user->getEmail(),
            existingCredentialIds: $existingIds,
        );

        $optionsJson = $this->webauthnService->serializeOptions($options);
        $request->getSession()->set(self::SESSION_OPTIONS_KEY, $optionsJson);

        return $this->render('profile/webauthn_register.html.twig', [
            'optionsJson' => $optionsJson,
        ]);
    }

    #[Route('/register', name: 'profile_webauthn_register_save', methods: ['POST'])]
    public function save(#[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('webauthn_register', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim($request->getPayload()->getString('name'));
        $responseJson = $request->getPayload()->getString('response');

        if ($name === '' || $responseJson === '') {
            $this->addFlash('error', 'Invalid request.');

            return $this->redirectToRoute('profile_webauthn_register');
        }

        $optionsJson = $request->getSession()->get(self::SESSION_OPTIONS_KEY);
        if (!\is_string($optionsJson)) {
            $this->addFlash('error', 'Registration session expired. Please try again.');

            return $this->redirectToRoute('profile_webauthn_register');
        }

        try {
            $credentialRecord = $this->webauthnService->verifyRegistration($responseJson, $optionsJson);
        } catch (Throwable) {
            $this->addFlash('error', 'Security key verification failed. Please try again.');

            return $this->redirectToRoute('profile_webauthn_register');
        }

        $credentialId = $this->webauthnService->encodeBase64Url($credentialRecord->publicKeyCredentialId);

        if ($this->credentialRepo->findByCredentialId($credentialId) !== null) {
            $this->addFlash('error', 'This security key is already registered.');

            return $this->redirectToRoute('profile_webauthn_register');
        }

        $credential = new WebauthnCredential(
            user: $user,
            name: $name,
            credentialId: $credentialId,
            credentialSource: $this->webauthnService->serializeCredentialRecord($credentialRecord),
        );

        $this->em->persist($credential);
        $this->em->flush();

        $request->getSession()->remove(self::SESSION_OPTIONS_KEY);
        $this->auditLogger->logSecurityEvent('webauthn.key.added', $user->getId()->toRfc4122());

        $this->addFlash('success', \sprintf('"%s" has been registered.', $name));

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/{id}/remove', name: 'profile_webauthn_remove', methods: ['POST'])]
    public function remove(string $id, #[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('webauthn_remove_' . $id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $credential = $this->credentialRepo->find($id);
        if ($credential === null || !$credential->getUser()->getId()->equals($user->getId())) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($credential);
        $this->em->flush();

        $this->auditLogger->logSecurityEvent('webauthn.key.removed', $user->getId()->toRfc4122());

        $this->addFlash('success', \sprintf('"%s" has been removed.', $credential->getName()));

        return $this->redirectToRoute('profile_security');
    }
}
