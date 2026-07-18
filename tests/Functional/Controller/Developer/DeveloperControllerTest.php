<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Developer;

use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DeveloperControllerTest extends FunctionalTestCase
{
    #[Test]
    public function indexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/developer');

        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function indexRendersForAuthenticatedUser(): void
    {
        $user = $this->createUserWithOrg('dev-index@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/developer');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'OAuth Applications');
    }

    #[Test]
    public function createAppIssuesClientIdAndOneTimeSecret(): void
    {
        $user = $this->createUserWithOrg('dev-create@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/developer/apps/new', [
            'o_auth_client' => [
                'name' => 'My App',
                'allowedGrants' => ['client_credentials'],
                'allowedScopes' => ['api:read'],
                'redirectUrisRaw' => '',
                '_token' => $this->getCsrfToken('o_auth_client'),
            ],
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'My App');
        $this->assertSelectorTextContains('body', "won't be shown again");
    }

    #[Test]
    public function secretIsNotShownAgainOnRevisit(): void
    {
        $user = $this->createUserWithOrg('dev-secret-once@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);
        [$client] = $clientService->createClient(name: 'App', grants: ['client_credentials'], scopes: ['api:read'], organization: $org);

        $this->client->loginUser($user);
        $this->client->request('GET', '/developer/apps/' . $client->getId()->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('body:contains("won\'t be shown again")');
    }

    #[Test]
    public function ownerCannotViewAnotherOrgsClient(): void
    {
        $owner = $this->createUserWithOrg('dev-owner@example.com');
        $ownerOrg = $owner->getOrganization();
        $this->assertNotNull($ownerOrg);
        $clientService = static::getContainer()->get(ClientService::class);
        [$client] = $clientService->createClient(name: 'App', grants: ['client_credentials'], scopes: ['api:read'], organization: $ownerOrg);

        $intruder = $this->createUserWithOrg('dev-intruder@example.com');
        $this->client->loginUser($intruder);

        $this->client->request('GET', '/developer/apps/' . $client->getId()->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function regenerateSecretInvalidatesPreviousSecret(): void
    {
        $user = $this->createUserWithOrg('dev-regen@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);
        [$client, $originalSecret] = $clientService->createClient(name: 'App', grants: ['client_credentials'], scopes: ['api:read'], organization: $org);

        $this->client->loginUser($user);
        $this->client->request('POST', '/developer/apps/' . $client->getId()->toRfc4122() . '/regenerate-secret', [
            '_token' => $this->getCsrfToken('regenerate_secret_' . $client->getId()->toRfc4122()),
        ]);

        $this->assertResponseRedirects('/developer/apps/' . $client->getId()->toRfc4122());

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getClientId(),
            'client_secret' => $originalSecret,
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function deleteAppRemovesClient(): void
    {
        $user = $this->createUserWithOrg('dev-delete@example.com');
        $org = $user->getOrganization();
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);
        [$client] = $clientService->createClient(name: 'App', grants: ['client_credentials'], scopes: ['api:read'], organization: $org);
        $clientId = $client->getId()->toRfc4122();

        $this->client->loginUser($user);
        $this->client->request('POST', '/developer/apps/' . $clientId . '/delete', [
            '_token' => $this->getCsrfToken('delete_client_' . $clientId),
        ]);

        $this->assertResponseRedirects('/developer/apps');
        $this->client->request('GET', '/developer/apps/' . $clientId);
        $this->assertResponseStatusCodeSame(404);
    }

    private function getCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
