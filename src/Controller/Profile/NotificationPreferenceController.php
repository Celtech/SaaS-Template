<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationPreferenceController extends AbstractController
{
    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'profile_notifications', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/notifications.html.twig', [
            'types' => NotificationType::cases(),
            'state' => $this->currentState($user),
        ]);
    }

    #[Route('', name: 'profile_notifications_update', methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('profile_notifications', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var array<string, array<string, mixed>> $submitted */
        $submitted = $request->request->all('preferences');

        foreach (NotificationType::cases() as $type) {
            foreach ($type->supportedChannels() as $channel) {
                $enabled = isset($submitted[$type->value][$channel]);
                $preference = $this->preferences->findOneForUserTypeChannel($user, $type->value, $channel);

                if ($preference === null) {
                    $this->preferences->save(new NotificationPreference($user, $type->value, $channel, $enabled));
                } else {
                    $preference->setEnabled($enabled);
                }
            }
        }

        $this->em->flush();
        $this->addFlash('success', 'Notification preferences updated.');

        return $this->redirectToRoute('profile_notifications');
    }

    /** @return array<string, array<string, bool>> [type value][channel] => enabled */
    private function currentState(User $user): array
    {
        $state = [];

        foreach (NotificationType::cases() as $type) {
            foreach ($type->supportedChannels() as $channel) {
                $preference = $this->preferences->findOneForUserTypeChannel($user, $type->value, $channel);
                $state[$type->value][$channel] = $preference?->isEnabled() ?? \in_array($channel, $type->defaultChannels(), true);
            }
        }

        return $state;
    }
}
