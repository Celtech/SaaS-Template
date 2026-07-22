<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\User;
use App\Form\ChangePasswordForm;
use App\Form\ProfileForm;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'profile_index')]
    public function index(#[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ProfileForm::class, [
            'name' => $user->getName(),
            'email' => $user->getEmail(),
        ]);

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/update', name: 'profile_update', methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ProfileForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = (string) $form->get('email')->getData();
            $newName = (string) $form->get('name')->getData();

            if ($newEmail !== $user->getEmail()) {
                $existing = $this->userRepository->findByEmail($newEmail);
                if ($existing !== null && !$existing->getId()->equals($user->getId())) {
                    $this->addFlash('error', 'That email address is already in use.');

                    return $this->redirectToRoute('profile_index');
                }
                $oldEmail = $user->getEmail();
                $user->setEmail($newEmail);
                $user->markEmailVerified(); // re-verify handled separately if needed

                $this->auditLogger->logSecurityEvent(
                    'email.changed',
                    $user->getId()->toRfc4122(),
                    ['old_email' => $oldEmail, 'new_email' => $newEmail],
                );
            }

            $user->setName($newName);
            $this->em->flush();

            $this->addFlash('success', 'Profile updated.');
        }

        return $this->redirectToRoute('profile_index');
    }

    #[Route('/change-password', name: 'profile_change_password', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ChangePasswordForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('error', 'Current password is incorrect.');

                return $this->redirectToRoute('profile_security');
            }

            $newPassword = $form->get('newPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->auditLogger->logSecurityEvent('password.changed', $user->getId()->toRfc4122());

            $this->addFlash('success', 'Password changed successfully.');
        }

        return $this->redirectToRoute('profile_security');
    }
}
