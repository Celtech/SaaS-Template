<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/2fa')]
#[IsGranted('ROLE_USER')]
class TwoFactorSetupController extends AbstractController
{
    private const SESSION_KEY = '_2fa_pending_secret';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        /** @phpstan-var non-empty-string */
        #[Autowire('%env(APP_NAME)%')]
        private readonly string $appName,
    ) {
    }

    #[Route('/setup', name: 'profile_2fa_setup', methods: ['GET'])]
    public function setup(#[CurrentUser] User $user, Request $request): Response
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('profile_security');
        }

        $session = $request->getSession();
        $secret = $session->get(self::SESSION_KEY);

        if (!\is_string($secret) || $secret === '') {
            $totp = TOTP::generate();
            $secret = $totp->getSecret();
            $session->set(self::SESSION_KEY, $secret);
        }

        \assert($secret !== '');
        $email = $user->getEmail();
        \assert($email !== '');

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($email);
        $totp->setIssuer($this->appName);

        $qrResult = new Builder(
            writer: new SvgWriter(),
            data: $totp->getProvisioningUri(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 192,
            margin: 8,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        )->build();

        return $this->render('profile/2fa_setup.html.twig', [
            'qr_code_uri' => $qrResult->getDataUri(),
            'totp_secret' => $secret,
        ]);
    }

    #[Route('/enable', name: 'profile_2fa_enable', methods: ['POST'])]
    public function enable(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('2fa_enable', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('profile_security');
        }

        $secret = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_string($secret) || $secret === '') {
            return $this->redirectToRoute('profile_2fa_setup');
        }

        $code = $request->getPayload()->getString('code');
        $totp = TOTP::createFromSecret($secret);
        if ($code === '' || !$totp->verify($code, null, 1)) {
            $this->addFlash('error', 'Invalid code — please try again.');

            return $this->redirectToRoute('profile_2fa_setup');
        }

        $user->enableTotp($secret);
        $this->em->flush();

        $request->getSession()->remove(self::SESSION_KEY);
        $this->auditLogger->logSecurityEvent('2fa.enabled', $user->getId()->toRfc4122());

        $this->addFlash('success', 'Two-factor authentication has been enabled.');

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/disable', name: 'profile_2fa_disable', methods: ['POST'])]
    public function disable(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('2fa_disable', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('profile_security');
        }

        $secret = $user->getTotpSecret();
        \assert($secret !== null && $secret !== '');

        $code = $request->getPayload()->getString('code');
        $totp = TOTP::createFromSecret($secret);
        if ($code === '' || !$totp->verify($code, null, 1)) {
            $this->addFlash('error', 'Invalid code — two-factor authentication has not been disabled.');

            return $this->redirectToRoute('profile_security');
        }

        $user->disableTotp();
        $this->em->flush();

        $this->auditLogger->logSecurityEvent('2fa.disabled', $user->getId()->toRfc4122());

        $this->addFlash('success', 'Authenticator app two-factor authentication has been disabled.');

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/email/enable', name: 'profile_2fa_email_enable', methods: ['POST'])]
    public function enableEmail(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('2fa_email_enable', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($user->isEmailAuthEnabled()) {
            return $this->redirectToRoute('profile_security');
        }

        $user->enableEmailAuth();
        $this->em->flush();

        $this->auditLogger->logSecurityEvent('2fa.email.enabled', $user->getId()->toRfc4122());

        $this->addFlash('success', 'Email code two-factor authentication has been enabled.');

        return $this->redirectToRoute('profile_security');
    }

    #[Route('/email/disable', name: 'profile_2fa_email_disable', methods: ['POST'])]
    public function disableEmail(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('2fa_email_disable', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$user->isEmailAuthEnabled()) {
            return $this->redirectToRoute('profile_security');
        }

        $user->disableEmailAuth();
        $this->em->flush();

        $this->auditLogger->logSecurityEvent('2fa.email.disabled', $user->getId()->toRfc4122());

        $this->addFlash('success', 'Email code two-factor authentication has been disabled.');

        return $this->redirectToRoute('profile_security');
    }
}
