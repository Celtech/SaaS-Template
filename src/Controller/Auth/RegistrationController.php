<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Form\RegistrationForm;
use App\Message\Mail\SendMailMessage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/auth')]
class RegistrationController extends AbstractController
{
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const PENDING_EMAIL_SESSION_KEY = 'pending_verification_email';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/register', name: 'auth_register')]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(RegistrationForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $plainPassword = $form->get('plainPassword')->getData();

            $user = new User($data['email'], $data['name']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $token = new EmailVerificationToken($user);
            $this->em->persist($user);
            $this->em->persist($token);
            $this->em->flush();

            $this->sendVerificationEmail($user, $token);

            $request->getSession()->set(self::PENDING_EMAIL_SESSION_KEY, $user->getEmail());

            return $this->redirectToRoute('auth_verify_email_notice');
        }

        return $this->render('auth/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/verify-email-notice', name: 'auth_verify_email_notice', methods: ['GET'])]
    public function verifyEmailNotice(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $email = $request->getSession()->get(self::PENDING_EMAIL_SESSION_KEY);
        if (!$email) {
            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/verify_email_notice.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route('/verify-email/{token}', name: 'auth_verify_email')]
    public function verifyEmail(string $token): Response
    {
        $tokenEntity = $this->em->getRepository(EmailVerificationToken::class)
            ->findValidByToken($token);

        if ($tokenEntity === null) {
            $this->addFlash('error', 'This verification link is invalid or has expired.');

            return $this->redirectToRoute('auth_login');
        }

        $user = $tokenEntity->getUser();
        $user->markEmailVerified();
        $tokenEntity->markUsed();
        $this->em->flush();

        $this->addFlash('success', 'Your email address has been verified. You can now sign in.');

        return $this->redirectToRoute('auth_login');
    }

    #[Route('/resend-verification', name: 'auth_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resend_verification', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = strtolower(trim($request->getPayload()->getString('email')));
        $redirectTo = $request->getPayload()->getString('redirect_to', 'auth_login');
        if (!\in_array($redirectTo, ['auth_login', 'auth_verify_email_notice'], true)) {
            $redirectTo = 'auth_login';
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user && !$user->isEmailVerified()) {
            $tokenRepo = $this->em->getRepository(EmailVerificationToken::class);
            $latest = $tokenRepo->findLatestActiveForUser($user);

            if ($latest !== null) {
                $elapsed = new DateTimeImmutable()->getTimestamp() - $latest->getCreatedAt()->getTimestamp();
                $remaining = self::RESEND_COOLDOWN_SECONDS - $elapsed;

                if ($remaining > 0) {
                    $this->addFlash('warning', \sprintf(
                        'Please wait %d more second%s before requesting another verification email.',
                        $remaining,
                        $remaining === 1 ? '' : 's'
                    ));

                    return $this->redirectToRoute($redirectTo);
                }
            }

            $tokenRepo->invalidateAllForUser($user);
            $newToken = new EmailVerificationToken($user);
            $this->em->persist($newToken);
            $this->em->flush();

            $this->sendVerificationEmail($user, $newToken);
            $this->addFlash('success', 'Verification email resent. Please check your inbox.');
        }

        return $this->redirectToRoute($redirectTo);
    }

    private function sendVerificationEmail(User $user, EmailVerificationToken $token): void
    {
        $verifyUrl = $this->generateUrl(
            'auth_verify_email',
            ['token' => $token->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->bus->dispatch(new SendMailMessage(
            'email/verify_email.html.twig',
            $user->getEmail(),
            ['name' => $user->getName(), 'verify_url' => $verifyUrl],
        ));
    }
}
