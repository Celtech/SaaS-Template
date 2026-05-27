<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RoleContext;
use App\Entity\User;
use App\Form\OrgSettingsType;
use App\Repository\OrgInvitationRepository;
use App\Repository\RoleRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Repository\UserRoleRepository;
use App\Service\Audit\AuditLogger;
use App\Service\OrgMemberService;
use App\Service\Stripe\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/org')]
final class OrgSettingsController extends AbstractController
{
    #[Route('/settings', name: 'org_settings', methods: ['GET', 'POST'])]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        UserRoleRepository $userRoleRepository,
        RoleRepository $roleRepository,
        OrgInvitationRepository $invitationRepository,
        AuditLogger $auditLogger,
        #[Autowire(param: 'billing.enabled')]
        bool $billingEnabled,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $org = $currentUser->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        $canManageSettings = $this->isGranted('org.settings.manage');
        $form = $this->createForm(OrgSettingsType::class, $org);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$canManageSettings) {
                throw $this->createAccessDeniedException();
            }

            $oldName = $em->getUnitOfWork()->getOriginalEntityData($org)['name'] ?? $org->getName();
            $em->flush();

            $auditLogger->logBillingEvent(
                'org.settings.updated',
                $org->getId()->toRfc4122(),
                'organization',
                ['name' => $oldName],
                ['name' => $org->getName()],
                $currentUser->getId()->toRfc4122(),
            );

            $this->addFlash('success', 'Organization settings saved.');

            return $this->redirectToRoute('org_settings');
        }

        $members = $userRepository->findByOrganization($org);
        $memberRoles = [];
        foreach ($members as $member) {
            $memberRoles[$member->getId()->toRfc4122()] = $userRoleRepository->findForUser($member, $org->getId());
        }

        $assignableRoles = $roleRepository->findByContext(RoleContext::Org);

        $canInviteMembers = $this->isGranted('org.members.invite');

        return $this->render('org/settings.html.twig', [
            'org' => $org,
            'form' => $form,
            'members' => $members,
            'memberRoles' => $memberRoles,
            'assignableRoles' => $assignableRoles,
            'canManageSettings' => $canManageSettings,
            'canManageMembers' => $this->isGranted('org.members.manage'),
            'canRemoveMembers' => $this->isGranted('org.members.remove'),
            'canInviteMembers' => $canInviteMembers,
            'pendingInvitations' => $canInviteMembers ? $invitationRepository->findPendingByOrg($org) : [],
            'billingEnabled' => $billingEnabled,
        ]);
    }

    #[Route('/settings/billing', name: 'org_settings_billing', methods: ['GET'])]
    public function billing(
        SubscriptionRepository $subscriptionRepository,
        StripeService $stripeService,
        #[Autowire(param: 'billing.enabled')]
        bool $billingEnabled,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $org = $currentUser->getOrganization();

        if ($org === null) {
            return $this->redirectToRoute('onboarding_org');
        }

        if (!$billingEnabled) {
            return $this->redirectToRoute('org_settings');
        }

        $subscription = $subscriptionRepository->findForOrg($org);
        $invoices = [];

        if ($subscription?->getStripeCustomerId() !== null) {
            try {
                $invoices = $stripeService->listInvoices($subscription->getStripeCustomerId())->data;
            } catch (ApiErrorException) {
                // Non-fatal — billing history just won't show
            }
        }

        return $this->render('org/settings_billing.html.twig', [
            'org' => $org,
            'subscription' => $subscription,
            'invoices' => $invoices,
            'billingEnabled' => $billingEnabled,
        ]);
    }

    #[Route('/members/{userId}/remove', name: 'org_member_remove', methods: ['POST'])]
    public function removeMember(
        string $userId,
        Request $request,
        UserRepository $userRepository,
        OrgMemberService $memberService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $org = $currentUser->getOrganization();

        if ($org === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('org.members.remove');

        if (!$this->isCsrfTokenValid('org_member_remove_' . $userId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('org_settings');
        }

        $member = $userRepository->find(Uuid::fromString($userId));
        if ($member === null || !$member->getOrganization()?->getId()->equals($org->getId())) {
            throw $this->createNotFoundException();
        }

        try {
            $memberService->removeMember($org, $member, $currentUser);
            $this->addFlash('success', \sprintf('%s has been removed from the organization.', $member->getName()));
        } catch (LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('org_settings');
    }

    #[Route('/members/{userId}/role', name: 'org_member_role', methods: ['POST'])]
    public function changeMemberRole(
        string $userId,
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        OrgMemberService $memberService,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $org = $currentUser->getOrganization();

        if ($org === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('org.members.manage');

        if (!$this->isCsrfTokenValid('org_member_role_' . $userId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('org_settings');
        }

        $member = $userRepository->find(Uuid::fromString($userId));
        if ($member === null || !$member->getOrganization()?->getId()->equals($org->getId())) {
            throw $this->createNotFoundException();
        }

        $roleSlug = (string) $request->request->get('role');
        $role = $roleRepository->findBySlug($roleSlug);
        if ($role === null || $role->getContext() !== RoleContext::Org) {
            $this->addFlash('error', 'Invalid role.');

            return $this->redirectToRoute('org_settings');
        }

        try {
            $memberService->changeMemberRole($org, $member, $role);
            $this->addFlash('success', \sprintf('%s\'s role updated to %s.', $member->getName(), $role->getName()));
        } catch (LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('org_settings');
    }
}
