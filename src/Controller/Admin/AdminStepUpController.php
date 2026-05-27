<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Enforcement\AdminStepUpEnforcement;
use App\Service\Audit\AuditLogger;
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
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_stepup', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $password = $request->request->getString('password');

            if ($this->hasher->isPasswordValid($user, $password)) {
                $request->getSession()->set(AdminStepUpEnforcement::SESSION_KEY, time());

                $this->auditLogger->logAdminAuth('stepup.confirmed', $user->getId()->toRfc4122());

                $returnUrl = $request->getSession()->get(AdminStepUpEnforcement::RETURN_URL_KEY);
                $request->getSession()->remove(AdminStepUpEnforcement::RETURN_URL_KEY);

                return $this->redirect(\is_string($returnUrl) ? $returnUrl : $this->generateUrl('admin_dashboard'));
            }

            $this->auditLogger->logAdminAuth('stepup.failed', $user->getId()->toRfc4122());

            $this->addFlash('error', 'Incorrect password. Please try again.');
        }

        return $this->render('admin/stepup/confirm.html.twig');
    }
}
