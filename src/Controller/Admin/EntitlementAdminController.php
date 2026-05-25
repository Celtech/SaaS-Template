<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Entitlement;
use App\Entity\User;
use App\Form\Admin\EntitlementType;
use App\Repository\EntitlementRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/entitlements')]
final class EntitlementAdminController extends AbstractController
{
    #[Route('', name: 'admin_entitlements_index', methods: ['GET'])]
    public function index(EntitlementRepository $entitlementRepository): Response
    {
        return $this->render('admin/entitlements/index.html.twig', [
            'entitlements' => $entitlementRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_entitlements_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        $entitlement = new Entitlement('', '', \App\Entity\EntitlementType::Boolean);
        $form = $this->createForm(EntitlementType::class, $entitlement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entitlement);
            $em->flush();

            /** @var User $admin */
            $admin = $this->getUser();
            $auditLogger->logAdminAction(
                'entitlement.created',
                $admin->getId()->toRfc4122(),
                $entitlement->getId()->toRfc4122(),
                'entitlement',
                null,
                ['slug' => $entitlement->getSlug(), 'type' => $entitlement->getType()->value],
            );

            $this->addFlash('success', \sprintf('Entitlement "%s" created.', $entitlement->getName()));

            return $this->redirectToRoute('admin_entitlements_index');
        }

        return $this->render('admin/entitlements/form.html.twig', [
            'form' => $form,
            'entitlement' => $entitlement,
            'title' => 'New entitlement',
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_entitlements_edit', methods: ['GET', 'POST'])]
    public function edit(
        Entitlement $entitlement,
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        $oldSlug = $entitlement->getSlug();
        $oldType = $entitlement->getType()->value;

        $form = $this->createForm(EntitlementType::class, $entitlement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            /** @var User $admin */
            $admin = $this->getUser();
            $auditLogger->logAdminAction(
                'entitlement.updated',
                $admin->getId()->toRfc4122(),
                $entitlement->getId()->toRfc4122(),
                'entitlement',
                ['slug' => $oldSlug, 'type' => $oldType],
                ['slug' => $entitlement->getSlug(), 'type' => $entitlement->getType()->value],
            );

            $this->addFlash('success', \sprintf('Entitlement "%s" updated.', $entitlement->getName()));

            return $this->redirectToRoute('admin_entitlements_index');
        }

        return $this->render('admin/entitlements/form.html.twig', [
            'form' => $form,
            'entitlement' => $entitlement,
            'title' => \sprintf('Edit: %s', $entitlement->getName()),
        ]);
    }
}
