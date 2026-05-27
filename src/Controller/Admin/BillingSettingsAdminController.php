<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TrialExpiryBehavior;
use App\Entity\User;
use App\Repository\BillingSettingsRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/billing-settings')]
final class BillingSettingsAdminController extends AbstractController
{
    #[Route('', name: 'admin_billing_settings', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        BillingSettingsRepository $settingsRepository,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        $settings = $settingsRepository->getSettings();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('billing_settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');

                return $this->redirectToRoute('admin_billing_settings');
            }

            $old = [
                'trial_enabled' => $settings->isTrialEnabled(),
                'trial_days' => $settings->getTrialDays(),
                'trial_expiry_behavior' => $settings->getTrialExpiryBehavior()->value,
                'grace_period_days' => $settings->getGracePeriodDays(),
                'require_credit_card' => $settings->isRequireCreditCard(),
                'default_trial_plan_slug' => $settings->getDefaultTrialPlanSlug(),
            ];

            $settings->setTrialEnabled((bool) $request->request->get('trial_enabled'));
            $settings->setTrialDays(max(1, (int) $request->request->get('trial_days', 14)));
            $settings->setGracePeriodDays(max(0, (int) $request->request->get('grace_period_days', 3)));
            $settings->setRequireCreditCard((bool) $request->request->get('require_credit_card'));
            $settings->setDefaultTrialPlanSlug($request->request->getString('default_trial_plan_slug') ?: null);

            $behaviorValue = $request->request->getString('trial_expiry_behavior');
            $behavior = TrialExpiryBehavior::tryFrom($behaviorValue);
            if ($behavior !== null) {
                $settings->setTrialExpiryBehavior($behavior);
            }

            $em->flush();

            /** @var User $admin */
            $admin = $this->getUser();
            $auditLogger->logAdminAction(
                'billing_settings.updated',
                $admin->getId()->toRfc4122(),
                (string) $settings->getId(),
                'billing_settings',
                $old,
                [
                    'trial_enabled' => $settings->isTrialEnabled(),
                    'trial_days' => $settings->getTrialDays(),
                    'trial_expiry_behavior' => $settings->getTrialExpiryBehavior()->value,
                    'grace_period_days' => $settings->getGracePeriodDays(),
                    'require_credit_card' => $settings->isRequireCreditCard(),
                    'default_trial_plan_slug' => $settings->getDefaultTrialPlanSlug(),
                ],
            );

            $this->addFlash('success', 'Billing settings saved.');

            return $this->redirectToRoute('admin_billing_settings');
        }

        return $this->render('admin/billing_settings/edit.html.twig', [
            'settings' => $settings,
            'behaviors' => TrialExpiryBehavior::cases(),
        ]);
    }
}
