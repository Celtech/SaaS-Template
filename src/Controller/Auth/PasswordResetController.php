<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\PasswordResetToken;
use App\Form\ForgotPasswordForm;
use App\Form\ResetPasswordForm;
use App\Message\Mail\SendMailMessage;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/auth')]
class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly PasswordResetTokenRepository $tokenRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/forgot-password', name: 'auth_forgot_password')]
    public function forgotPassword(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $this->userRepository->findByEmail($email);

            if ($user !== null) {
                $this->tokenRepository->invalidateAllForUser($user);
                $token = new PasswordResetToken($user);
                $this->em->persist($token);
                $this->em->flush();

                $resetUrl = $this->generateUrl(
                    'auth_reset_password',
                    ['token' => $token->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $this->bus->dispatch(new SendMailMessage(
                    'email/reset_password.html.twig',
                    $user->getEmail(),
                    ['name' => $user->getName(), 'reset_url' => $resetUrl],
                ));

                $this->auditLogger->logAuth('password_reset.requested', $user->getId()->toRfc4122());
            }

            // Always show success to prevent email enumeration
            $this->addFlash('success', 'If an account with that email exists, a password reset link has been sent.');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/forgot_password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'auth_reset_password')]
    public function resetPassword(Request $request, string $token): Response
    {
        $tokenEntity = $this->tokenRepository->findValidByToken($token);

        if ($tokenEntity === null) {
            $this->addFlash('error', 'This password reset link is invalid or has expired.');

            return $this->redirectToRoute('auth_forgot_password');
        }

        $form = $this->createForm(ResetPasswordForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user = $tokenEntity->getUser();

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->resetFailedLoginCount();
            $user->unlock();
            // Receiving and clicking the reset link proves inbox ownership
            if (!$user->isEmailVerified()) {
                $user->markEmailVerified();
            }
            $tokenEntity->markUsed();
            $this->em->flush();

            $this->auditLogger->logAuth('password_reset.completed', $user->getId()->toRfc4122());
            $this->addFlash('success', 'Your password has been reset. You can now log in.');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/reset_password.html.twig', [
            'form' => $form,
            'token' => $token,
        ]);
    }
}
