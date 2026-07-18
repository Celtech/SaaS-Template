<?php

declare(strict_types=1);

namespace App\Controller\Developer;

use App\Entity\User;
use App\Entity\WebhookEndpoint;
use App\Form\Developer\WebhookEndpointType;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use App\Service\Webhook\WebhookDispatcher;
use App\Service\Webhook\WebhookEndpointService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/developer/webhooks')]
final class WebhookController extends AbstractController
{
    #[Route('', name: 'developer_webhooks_index', methods: ['GET'])]
    public function index(WebhookEndpointRepository $endpoints): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        return $this->render('developer/webhooks/index.html.twig', [
            'endpoints' => $org !== null ? $endpoints->findForOrganization($org) : [],
        ]);
    }

    #[Route('/new', name: 'developer_webhooks_new', methods: ['GET', 'POST'])]
    public function newEndpoint(Request $request, WebhookEndpointService $endpointService): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            $this->addFlash('error', 'You must belong to an organization to create webhook endpoints.');

            return $this->redirectToRoute('developer_webhooks_index');
        }

        $form = $this->createForm(WebhookEndpointType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{url: string, events: string[]} $data */
            $data = $form->getData();

            [$endpoint, $plainSecret] = $endpointService->createEndpoint(
                organization: $org,
                url: $data['url'],
                events: $data['events'],
                actorId: $user->getId()->toRfc4122(),
            );

            $request->getSession()->set('_webhook_new_secret_' . $endpoint->getId()->toRfc4122(), $plainSecret);

            return $this->redirectToRoute('developer_webhooks_show', ['id' => $endpoint->getId()->toRfc4122()]);
        }

        return $this->render('developer/webhooks/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'developer_webhooks_show', methods: ['GET'])]
    public function showEndpoint(WebhookEndpoint $endpoint, Request $request, WebhookDeliveryRepository $deliveries): Response
    {
        $this->denyAccessUnlessOrgOwnsEndpoint($endpoint);

        $sessionKey = '_webhook_new_secret_' . $endpoint->getId()->toRfc4122();
        $plainSecret = $request->getSession()->get($sessionKey);
        if ($plainSecret !== null) {
            $request->getSession()->remove($sessionKey);
        }

        return $this->render('developer/webhooks/show.html.twig', [
            'endpoint' => $endpoint,
            'plainSecret' => $plainSecret,
            'deliveries' => $deliveries->findForEndpoint($endpoint),
        ]);
    }

    #[Route('/{id}/edit', name: 'developer_webhooks_edit', methods: ['GET', 'POST'])]
    public function editEndpoint(WebhookEndpoint $endpoint, Request $request, WebhookEndpointService $endpointService): Response
    {
        $this->denyAccessUnlessOrgOwnsEndpoint($endpoint);

        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(WebhookEndpointType::class, [
            'url' => $endpoint->getUrl(),
            'events' => $endpoint->getEvents(),
            'isActive' => $endpoint->isActive(),
        ], ['include_active_toggle' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{url: string, events: string[], isActive: bool} $data */
            $data = $form->getData();

            $endpointService->updateEndpoint(
                endpoint: $endpoint,
                url: $data['url'],
                events: $data['events'],
                isActive: $data['isActive'] ?? false,
                actorId: $user->getId()->toRfc4122(),
            );

            $this->addFlash('success', 'Webhook endpoint updated.');

            return $this->redirectToRoute('developer_webhooks_show', ['id' => $endpoint->getId()->toRfc4122()]);
        }

        return $this->render('developer/webhooks/edit.html.twig', [
            'endpoint' => $endpoint,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/regenerate-secret', name: 'developer_webhooks_regenerate_secret', methods: ['POST'])]
    public function regenerateSecret(WebhookEndpoint $endpoint, Request $request, WebhookEndpointService $endpointService): Response
    {
        $this->denyAccessUnlessOrgOwnsEndpoint($endpoint);

        if (!$this->isCsrfTokenValid('regenerate_webhook_secret_' . $endpoint->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $plainSecret = $endpointService->regenerateSecret($endpoint, actorId: $user->getId()->toRfc4122());
        $request->getSession()->set('_webhook_new_secret_' . $endpoint->getId()->toRfc4122(), $plainSecret);

        $this->addFlash('warning', 'Secret regenerated. Your previous secret is now invalid.');

        return $this->redirectToRoute('developer_webhooks_show', ['id' => $endpoint->getId()->toRfc4122()]);
    }

    #[Route('/{id}/test', name: 'developer_webhooks_test', methods: ['POST'])]
    public function testEndpoint(WebhookEndpoint $endpoint, Request $request, WebhookDispatcher $dispatcher): Response
    {
        $this->denyAccessUnlessOrgOwnsEndpoint($endpoint);

        if (!$this->isCsrfTokenValid('test_webhook_' . $endpoint->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $dispatcher->sendTest($endpoint);
        $this->addFlash('success', 'Test event queued — check the delivery log below shortly.');

        return $this->redirectToRoute('developer_webhooks_show', ['id' => $endpoint->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'developer_webhooks_delete', methods: ['POST'])]
    public function deleteEndpoint(WebhookEndpoint $endpoint, Request $request, WebhookEndpointService $endpointService): Response
    {
        $this->denyAccessUnlessOrgOwnsEndpoint($endpoint);

        if (!$this->isCsrfTokenValid('delete_webhook_' . $endpoint->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $endpointService->deleteEndpoint($endpoint, actorId: $user->getId()->toRfc4122());
        $this->addFlash('success', 'Webhook endpoint deleted.');

        return $this->redirectToRoute('developer_webhooks_index');
    }

    private function denyAccessUnlessOrgOwnsEndpoint(WebhookEndpoint $endpoint): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null || !$endpoint->getOrganization()->getId()->equals($org->getId())) {
            throw $this->createAccessDeniedException();
        }
    }
}
