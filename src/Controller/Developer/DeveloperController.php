<?php

declare(strict_types=1);

namespace App\Controller\Developer;

use App\Entity\OAuthClient;
use App\Entity\User;
use App\Form\Developer\OAuthClientType;
use App\Repository\OAuthClientRepository;
use App\Service\OAuth\ClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/developer')]
final class DeveloperController extends AbstractController
{
    #[Route('', name: 'developer_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('developer/index.html.twig');
    }

    #[Route('/apps', name: 'developer_apps_index', methods: ['GET'])]
    public function apps(OAuthClientRepository $clients): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.view');

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        return $this->render('developer/apps/index.html.twig', [
            'clients' => $org !== null ? $clients->findForOrganization($org) : [],
        ]);
    }

    #[Route('/apps/new', name: 'developer_apps_new', methods: ['GET', 'POST'])]
    public function newApp(Request $request, ClientService $clientService): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.manage');

        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null) {
            $this->addFlash('error', 'You must belong to an organization to create OAuth applications.');

            return $this->redirectToRoute('developer_apps_index');
        }

        $form = $this->createForm(OAuthClientType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, description: ?string, allowedGrants: string[], allowedScopes: string[]} $data */
            $data = $form->getData();

            $rawUris = $form->get('redirectUrisRaw')->getData() ?? '';
            $uris = array_filter(array_map('trim', explode("\n", (string) $rawUris)));

            [$client, $plainSecret] = $clientService->createClient(
                name: $data['name'],
                grants: $data['allowedGrants'],
                scopes: $data['allowedScopes'],
                redirectUris: array_values($uris),
                organization: $org,
                description: $data['description'] ?? null,
                actorId: $user->getId()->toRfc4122(),
            );

            // Store secret in session flash so we can show it once on the next page.
            $request->getSession()->set('_oauth_new_secret_' . $client->getId()->toRfc4122(), $plainSecret);

            return $this->redirectToRoute('developer_apps_show', ['id' => $client->getId()->toRfc4122()]);
        }

        return $this->render('developer/apps/new.html.twig', ['form' => $form]);
    }

    #[Route('/apps/{id}', name: 'developer_apps_show', methods: ['GET'])]
    public function showApp(OAuthClient $client, Request $request): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.view');
        $this->denyAccessUnlessOrgOwnsClient($client);

        $sessionKey = '_oauth_new_secret_' . $client->getId()->toRfc4122();
        $plainSecret = $request->getSession()->get($sessionKey);
        if ($plainSecret !== null) {
            $request->getSession()->remove($sessionKey);
        }

        return $this->render('developer/apps/show.html.twig', [
            'client' => $client,
            'plainSecret' => $plainSecret,
        ]);
    }

    #[Route('/apps/{id}/edit', name: 'developer_apps_edit', methods: ['GET', 'POST'])]
    public function editApp(OAuthClient $client, Request $request, OAuthClientRepository $clients): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.manage');
        $this->denyAccessUnlessOrgOwnsClient($client);

        $form = $this->createForm(OAuthClientType::class, [
            'name' => $client->getName(),
            'description' => $client->getDescription(),
            'allowedGrants' => $client->getAllowedGrants(),
            'allowedScopes' => $client->getAllowedScopes(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, description: ?string, allowedGrants: string[], allowedScopes: string[]} $data */
            $data = $form->getData();

            $rawUris = $form->get('redirectUrisRaw')->getData() ?? '';
            $uris = array_filter(array_map('trim', explode("\n", (string) $rawUris)));

            $client->setName($data['name']);
            $client->setDescription($data['description'] ?? null);
            $client->setAllowedGrants($data['allowedGrants']);
            $client->setAllowedScopes($data['allowedScopes']);
            $client->setRedirectUris(array_values($uris));
            $clients->save($client, flush: true);

            $this->addFlash('success', 'Application updated.');

            return $this->redirectToRoute('developer_apps_show', ['id' => $client->getId()->toRfc4122()]);
        }

        return $this->render('developer/apps/edit.html.twig', [
            'client' => $client,
            'form' => $form,
        ]);
    }

    #[Route('/apps/{id}/regenerate-secret', name: 'developer_apps_regenerate_secret', methods: ['POST'])]
    public function regenerateSecret(OAuthClient $client, Request $request, ClientService $clientService): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.manage');
        $this->denyAccessUnlessOrgOwnsClient($client);

        if (!$this->isCsrfTokenValid('regenerate_secret_' . $client->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $plainSecret = $clientService->regenerateSecret($client, actorId: $user->getId()->toRfc4122());
        $request->getSession()->set('_oauth_new_secret_' . $client->getId()->toRfc4122(), $plainSecret);

        $this->addFlash('warning', 'Secret regenerated. Your previous secret is now invalid.');

        return $this->redirectToRoute('developer_apps_show', ['id' => $client->getId()->toRfc4122()]);
    }

    #[Route('/apps/{id}/delete', name: 'developer_apps_delete', methods: ['POST'])]
    public function deleteApp(OAuthClient $client, Request $request, ClientService $clientService): Response
    {
        $this->denyAccessUnlessGranted('org.api_keys.manage');
        $this->denyAccessUnlessOrgOwnsClient($client);

        if (!$this->isCsrfTokenValid('delete_client_' . $client->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $clientService->deleteClient($client, actorId: $user->getId()->toRfc4122());
        $this->addFlash('success', 'Application deleted.');

        return $this->redirectToRoute('developer_apps_index');
    }

    private function denyAccessUnlessOrgOwnsClient(OAuthClient $client): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $user->getOrganization();

        if ($org === null || $client->getOrganization() === null || !$client->getOrganization()->getId()->equals($org->getId())) {
            throw $this->createAccessDeniedException();
        }
    }
}
