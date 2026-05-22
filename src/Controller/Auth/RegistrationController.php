<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Form\RegistrationForm;
use App\Message\Mail\SendMailMessage;
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

            $this->addFlash('success', 'Account created! Please check your email to verify your address.');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/verify-email/{token}', name: 'auth_verify_email')]
    public function verifyEmail(string $token): Response
    {
        $tokenEntity = $this->em->getRepository(\App\Entity\EmailVerificationToken::class)
            ->findValidByToken($token);

        if ($tokenEntity === null) {
            $this->addFlash('error', 'This verification link is invalid or has expired.');

            return $this->redirectToRoute('auth_login');
        }

        $user = $tokenEntity->getUser();
        $user->markEmailVerified();
        $tokenEntity->markUsed();
        $this->em->flush();

        $this->addFlash('success', 'Your email address has been verified. You can now log in.');

        return $this->redirectToRoute('auth_login');
    }
}
