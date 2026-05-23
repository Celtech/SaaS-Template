<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Plan;
use App\Entity\PlanEntitlement;
use App\Entity\User;
use App\Form\Admin\PlanType;
use App\Repository\EntitlementRepository;
use App\Repository\PlanRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Stripe\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/plans')]
final class PlanAdminController extends AbstractController
{
    #[Route('', name: 'admin_plans_index', methods: ['GET'])]
    public function index(PlanRepository $planRepository): Response
    {
        return $this->render('admin/plans/index.html.twig', [
            'plans' => $planRepository->findBy([], ['sortOrder' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_plans_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        StripeService $stripeService,
        AuditLogger $auditLogger,
    ): Response {
        $plan = new Plan('', '');
        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if (!$plan->isFree()) {
                    $product = $stripeService->createProduct($plan->getName(), $plan->getDescription());
                    $plan->setStripeProductId($product->id);

                    if ($plan->getMonthlyPriceCents() > 0) {
                        $price = $stripeService->createPrice($product->id, $plan->getMonthlyPriceCents(), 'month');
                        $plan->setStripePriceIdMonthly($price->id);
                    }

                    if ($plan->getAnnualPriceCents() > 0) {
                        $price = $stripeService->createPrice($product->id, $plan->getAnnualPriceCents(), 'year');
                        $plan->setStripePriceIdAnnual($price->id);
                    }
                }

                $em->persist($plan);
                $em->flush();

                /** @var User $admin */
                $admin = $this->getUser();
                $auditLogger->logAdminAction(
                    'plan.created',
                    $admin->getId()->toRfc4122(),
                    $plan->getId()->toRfc4122(),
                    'plan',
                    null,
                    ['name' => $plan->getName(), 'slug' => $plan->getSlug()],
                );

                $this->addFlash('success', \sprintf('Plan "%s" created.', $plan->getName()));

                return $this->redirectToRoute('admin_plans_index');
            } catch (ApiErrorException $e) {
                $this->addFlash('error', 'Stripe error: ' . $e->getMessage());
            }
        }

        return $this->render('admin/plans/form.html.twig', [
            'form' => $form,
            'plan' => $plan,
            'title' => 'New plan',
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_plans_edit', methods: ['GET', 'POST'])]
    public function edit(
        Plan $plan,
        Request $request,
        EntityManagerInterface $em,
        StripeService $stripeService,
        AuditLogger $auditLogger,
    ): Response {
        $oldName = $plan->getName();
        $oldMonthly = $plan->getMonthlyPriceCents();
        $oldAnnual = $plan->getAnnualPriceCents();

        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($plan->getStripeProductId() !== null) {
                    // Sync product name/description if changed
                    if ($plan->getName() !== $oldName) {
                        $stripeService->updateProduct($plan->getStripeProductId(), $plan->getName(), $plan->getDescription());
                    }

                    // Recreate prices if amounts changed
                    $pricesChanged = $plan->getMonthlyPriceCents() !== $oldMonthly
                        || $plan->getAnnualPriceCents() !== $oldAnnual;

                    if ($pricesChanged) {
                        $stripeService->syncPlanPrices($plan);
                    }
                } elseif (!$plan->isFree()) {
                    // Plan was free and is now paid — create Stripe objects
                    $product = $stripeService->createProduct($plan->getName(), $plan->getDescription());
                    $plan->setStripeProductId($product->id);
                    $stripeService->syncPlanPrices($plan);
                }

                $em->flush();

                /** @var User $admin */
                $admin = $this->getUser();
                $auditLogger->logAdminAction(
                    'plan.updated',
                    $admin->getId()->toRfc4122(),
                    $plan->getId()->toRfc4122(),
                    'plan',
                    ['name' => $oldName, 'monthly_cents' => $oldMonthly, 'annual_cents' => $oldAnnual],
                    ['name' => $plan->getName(), 'monthly_cents' => $plan->getMonthlyPriceCents(), 'annual_cents' => $plan->getAnnualPriceCents()],
                );

                $this->addFlash('success', \sprintf('Plan "%s" updated.', $plan->getName()));

                return $this->redirectToRoute('admin_plans_index');
            } catch (ApiErrorException $e) {
                $this->addFlash('error', 'Stripe error: ' . $e->getMessage());
            }
        }

        return $this->render('admin/plans/form.html.twig', [
            'form' => $form,
            'plan' => $plan,
            'title' => \sprintf('Edit plan: %s', $plan->getName()),
        ]);
    }

    #[Route('/{id}/entitlements', name: 'admin_plans_entitlements', methods: ['GET', 'POST'])]
    public function entitlements(
        Plan $plan,
        Request $request,
        EntityManagerInterface $em,
        EntitlementRepository $entitlementRepository,
        AuditLogger $auditLogger,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('plan_entitlements_' . $plan->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');

                return $this->redirectToRoute('admin_plans_entitlements', ['id' => $plan->getId()]);
            }

            // Remove all existing plan entitlements and rebuild from POST
            foreach ($plan->getPlanEntitlements() as $pe) {
                $em->remove($pe);
            }

            $allEntitlements = $entitlementRepository->findAll();
            $values = (array) $request->request->all('entitlements');

            foreach ($allEntitlements as $entitlement) {
                $value = $values[$entitlement->getId()->toRfc4122()] ?? null;

                if ($value !== null && $value !== '') {
                    $em->persist(new PlanEntitlement($plan, $entitlement, (string) $value));
                }
            }

            $em->flush();

            /** @var User $admin */
            $admin = $this->getUser();
            $auditLogger->logAdminAction(
                'plan.entitlements.updated',
                $admin->getId()->toRfc4122(),
                $plan->getId()->toRfc4122(),
                'plan',
            );

            $this->addFlash('success', 'Entitlements updated.');

            return $this->redirectToRoute('admin_plans_entitlements', ['id' => $plan->getId()]);
        }

        $allEntitlements = $entitlementRepository->findBy([], ['name' => 'ASC']);
        $currentValues = [];
        foreach ($plan->getPlanEntitlements() as $pe) {
            $currentValues[$pe->getEntitlement()->getId()->toRfc4122()] = $pe->getValue();
        }

        return $this->render('admin/plans/entitlements.html.twig', [
            'plan' => $plan,
            'entitlements' => $allEntitlements,
            'currentValues' => $currentValues,
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_plans_toggle', methods: ['POST'])]
    public function toggle(
        Plan $plan,
        Request $request,
        EntityManagerInterface $em,
        StripeService $stripeService,
        AuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('plan_toggle_' . $plan->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('admin_plans_index');
        }

        $wasActive = $plan->isActive();
        $plan->setIsActive(!$wasActive);

        try {
            if ($plan->getStripeProductId() !== null && $wasActive) {
                $stripeService->archiveProduct($plan->getStripeProductId());
            }

            $em->flush();

            /** @var User $admin */
            $admin = $this->getUser();
            $auditLogger->logAdminAction(
                $plan->isActive() ? 'plan.activated' : 'plan.deactivated',
                $admin->getId()->toRfc4122(),
                $plan->getId()->toRfc4122(),
                'plan',
            );

            $this->addFlash('success', \sprintf(
                'Plan "%s" %s.',
                $plan->getName(),
                $plan->isActive() ? 'activated' : 'deactivated'
            ));
        } catch (ApiErrorException $e) {
            $this->addFlash('error', 'Stripe error: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_plans_index');
    }
}
