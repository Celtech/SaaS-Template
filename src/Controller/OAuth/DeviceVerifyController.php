<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\User;
use App\Security\OAuth\OAuthScope;
use App\Service\Audit\AuditLogger;
use App\Service\OAuth\DeviceCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** RFC 8628 §3.3 — the user-facing verification and consent flow for the Device Authorization grant. */
#[IsGranted('ROLE_USER')]
final class DeviceVerifyController extends AbstractController
{
    public function __construct(
        private readonly DeviceCodeService $deviceCodeService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/oauth/device', name: 'oauth_device_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $userCode = trim($request->query->getString('user_code'));

        if ($userCode === '') {
            return $this->render('oauth/device_entry.html.twig');
        }

        $deviceCode = $this->deviceCodeService->findByUserCode($userCode);

        if ($deviceCode === null || !$deviceCode->isPending()) {
            return $this->render('oauth/device_entry.html.twig', [
                'error' => 'That code is invalid or has expired. Double-check it and try again.',
            ]);
        }

        return $this->render('oauth/device_consent.html.twig', [
            'client' => $deviceCode->getClient(),
            'scopeEnums' => array_map(static fn (string $s) => OAuthScope::from($s), $deviceCode->getScopes()),
            'userCode' => $userCode,
        ]);
    }

    #[Route('/oauth/device/decide', name: 'oauth_device_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('oauth_device_verify', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $deviceCode = $this->deviceCodeService->findByUserCode($request->request->getString('user_code'));

        if ($deviceCode === null || !$deviceCode->isPending()) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $client = $deviceCode->getClient();

        if ($request->request->getString('decision') === 'approve') {
            $this->deviceCodeService->approve($deviceCode, $user, $user->getOrganization());
            $this->auditLogger->logOAuthEvent(
                'device_authorization.granted',
                $client->getId()->toRfc4122(),
                'oauth_client',
                newValue: ['scopes' => $deviceCode->getScopes()],
                actorId: $user->getId()->toRfc4122(),
            );

            return $this->render('oauth/device_result.html.twig', ['approved' => true, 'client' => $client]);
        }

        $this->deviceCodeService->deny($deviceCode);
        $this->auditLogger->logOAuthEvent(
            'device_authorization.denied',
            $client->getId()->toRfc4122(),
            'oauth_client',
            actorId: $user->getId()->toRfc4122(),
        );

        return $this->render('oauth/device_result.html.twig', ['approved' => false, 'client' => $client]);
    }
}
