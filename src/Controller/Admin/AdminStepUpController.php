<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Enforcement\AdminStepUpEnforcement;
use App\Service\Audit\AuditLogger;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/confirm', name: 'admin_stepup_confirm', methods: ['GET', 'POST'])]
final class AdminStepUpController extends AbstractController
{
    private const PASSWORD_OK_KEY = '_admin_stepup_password_ok';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();
        $passwordConfirmed = $session->get(self::PASSWORD_OK_KEY) === true;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_stepup', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $step = $request->request->getString('step');

            if ($step === 'password') {
                return $this->handlePasswordStep($request, $user);
            }

            if ($step === 'totp' && $passwordConfirmed) {
                return $this->handleTotpStep($request, $user);
            }

            // Tampered step field — restart.
            $session->remove(self::PASSWORD_OK_KEY);

            return $this->redirectToRoute('admin_stepup_confirm');
        }

        return $this->render('admin/stepup/confirm.html.twig', [
            'step' => $passwordConfirmed ? 'totp' : 'password',
        ]);
    }

    private function handlePasswordStep(Request $request, User $user): Response
    {
        if ($this->hasher->isPasswordValid($user, $request->request->getString('password'))) {
            $request->getSession()->set(self::PASSWORD_OK_KEY, true);

            return $this->redirectToRoute('admin_stepup_confirm');
        }

        $this->auditLogger->logAdminAuth('stepup.failed', $user->getId()->toRfc4122(), ['step' => 'password']);
        $this->addFlash('error', 'Incorrect password. Please try again.');

        return $this->render('admin/stepup/confirm.html.twig', ['step' => 'password']);
    }

    private function handleTotpStep(Request $request, User $user): Response
    {
        $secret = $user->getTotpSecret();
        $code = $request->request->getString('code');
        $session = $request->getSession();

        if ($secret !== null && $secret !== '' && $code !== '' && TOTP::createFromSecret($secret)->verify($code, null, 1)) {
            $session->remove(self::PASSWORD_OK_KEY);
            $session->set(AdminStepUpEnforcement::SESSION_KEY, time());

            $this->auditLogger->logAdminAuth('stepup.confirmed', $user->getId()->toRfc4122());

            $returnUrl = $session->get(AdminStepUpEnforcement::RETURN_URL_KEY);
            $session->remove(AdminStepUpEnforcement::RETURN_URL_KEY);

            return $this->redirect(\is_string($returnUrl) ? $returnUrl : $this->generateUrl('admin_dashboard'));
        }

        $this->auditLogger->logAdminAuth('stepup.failed', $user->getId()->toRfc4122(), ['step' => 'totp']);
        $this->addFlash('error', 'Invalid code. Please try again.');

        return $this->render('admin/stepup/confirm.html.twig', ['step' => 'totp']);
    }
}
