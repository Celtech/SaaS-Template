<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrgInvitation;
use App\Entity\Permission;
use App\Entity\User;
use App\Entity\UserRole;
use App\Enum\NotificationType;
use App\Enum\WebhookEvent;
use App\Message\Mail\SendMailMessage;
use App\Repository\OrgInvitationRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Repository\UserRoleRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Webhook\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OrgInvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrgInvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly UserRoleRepository $userRoleRepository,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function sendInvite(Organization $org, string $email, User $invitedBy): OrgInvitation
    {
        $email = strtolower(trim($email));

        if ($this->userRepository->findByEmail($email) !== null) {
            throw new InvalidArgumentException('A user with this email address already has an account.');
        }

        $existing = $this->invitationRepository->findPendingByEmail($email);
        if ($existing !== null && $existing->getOrganization()->getId()->equals($org->getId())) {
            throw new InvalidArgumentException('An invitation has already been sent to this email address.');
        }

        $invitation = new OrgInvitation($org, $email, $invitedBy);
        $this->em->persist($invitation);
        $this->em->flush();

        $registerUrl = $this->urlGenerator->generate(
            'auth_register',
            ['invite' => $invitation->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->bus->dispatch(new SendMailMessage(
            'email/org_invite.html.twig',
            $email,
            [
                'org_name' => $org->getName(),
                'invited_by_name' => $invitedBy->getName(),
                'register_url' => $registerUrl,
                'expires_hours' => OrgInvitation::TTL_HOURS,
            ],
        ));

        $this->webhookDispatcher->dispatch($org, WebhookEvent::OrgMemberInvited, [
            'email' => $email,
            'invited_by' => $invitedBy->getId()->toRfc4122(),
            'organization_id' => $org->getId()->toRfc4122(),
        ]);

        $this->notifyOrgAdmins(
            $org,
            NotificationType::OrgMemberInvited,
            'New member invited',
            \sprintf('%s invited %s to join %s.', $invitedBy->getName(), $email, $org->getName()),
            excluding: $invitedBy,
        );

        return $invitation;
    }

    public function acceptInvitation(OrgInvitation $invitation, User $user): void
    {
        $org = $invitation->getOrganization();
        $memberRole = $this->roleRepository->findBySlug('org-member');

        $user->setOrganization($org);

        if ($memberRole !== null) {
            $this->em->persist(new UserRole($user, $memberRole, $org->getId()));
        }

        $invitation->accept();
        $this->em->flush();

        $this->webhookDispatcher->dispatch($org, WebhookEvent::OrgMemberJoined, [
            'user_id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'organization_id' => $org->getId()->toRfc4122(),
        ]);

        $this->notifyOrgAdmins(
            $org,
            NotificationType::OrgMemberJoined,
            'New member joined',
            \sprintf('%s joined %s.', $user->getName(), $org->getName()),
        );
    }

    private function notifyOrgAdmins(Organization $org, NotificationType $type, string $title, string $body, ?User $excluding = null): void
    {
        $actionUrl = $this->urlGenerator->generate('org_settings', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($this->userRoleRepository->findUsersWithPermissionInOrg($org->getId(), Permission::OrgMembersManage) as $admin) {
            if ($excluding !== null && $admin->getId()->equals($excluding->getId())) {
                continue;
            }

            $this->notificationDispatcher->dispatch($admin, $type, $title, $body, $actionUrl);
        }
    }
}
