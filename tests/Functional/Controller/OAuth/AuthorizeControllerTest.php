<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AuthorizeControllerTest extends FunctionalTestCase
{
    #[Test]
    public function authorizeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/oauth/authorize', ['client_id' => 'anything']);

        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function authorizeRejectsUnknownClientWithErrorPage(): void
    {
        $user = $this->createUserWithOrg('authz-unknown-client@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/authorize', [
            'client_id' => 'does-not-exist',
            'redirect_uri' => 'https://example.com/callback',
            'response_type' => 'code',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSelectorTextContains('body', 'Unknown client_id');
    }

    #[Test]
    public function authorizeRejectsUnregisteredRedirectUriWithErrorPage(): void
    {
        $user = $this->createUserWithOrg('authz-bad-redirect@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/authorize', [
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://evil.example.com/callback',
            'response_type' => 'code',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSelectorTextContains('body', 'not registered');
    }

    #[Test]
    public function authorizeRedirectsWithErrorForUnsupportedResponseType(): void
    {
        $user = $this->createUserWithOrg('authz-bad-response-type@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/authorize', [
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'token',
            'state' => 'xyz',
        ]);

        $this->assertResponseRedirects('https://app.example.com/callback?error=unsupported_response_type&state=xyz');
    }

    #[Test]
    public function authorizeRedirectsWithErrorWhenPkceMissing(): void
    {
        $user = $this->createUserWithOrg('authz-no-pkce@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/authorize', [
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('error=invalid_request', (string) $response->headers->get('Location'));
    }

    #[Test]
    public function authorizeRendersConsentScreenForValidRequest(): void
    {
        $user = $this->createUserWithOrg('authz-consent@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/authorize', [
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://app.example.com/callback',
            'response_type' => 'code',
            'scope' => 'api:read',
            'code_challenge' => 'abc123',
            'code_challenge_method' => 'S256',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $client->getName());
        $this->assertSelectorTextContains('body', 'Read access to the API');
    }

    #[Test]
    public function decideApprovalIssuesCodeAndRedirects(): void
    {
        $user = $this->createUserWithOrg('authz-approve@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('POST', '/oauth/authorize', [
            '_token' => $this->getCsrfToken('oauth_authorize'),
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://app.example.com/callback',
            'scope' => 'api:read',
            'state' => 'xyz',
            'code_challenge' => 'abc123',
            'decision' => 'approve',
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.example.com/callback?code=', $location);
        $this->assertStringContainsString('state=xyz', $location);
    }

    #[Test]
    public function decideDenialRedirectsWithAccessDenied(): void
    {
        $user = $this->createUserWithOrg('authz-deny@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        $this->client->loginUser($user);

        $this->client->request('POST', '/oauth/authorize', [
            '_token' => $this->getCsrfToken('oauth_authorize'),
            'client_id' => $client->getClientId(),
            'redirect_uri' => 'https://app.example.com/callback',
            'scope' => 'api:read',
            'state' => 'xyz',
            'code_challenge' => 'abc123',
            'decision' => 'deny',
        ]);

        $this->assertResponseRedirects('https://app.example.com/callback?error=access_denied&state=xyz');
    }

    // Invalid-CSRF-token rejection is verified manually (live 403 confirmed
    // via browser), not here: this suite's when@test config aliases the CSRF
    // token manager to App\Tests\Support\AlwaysValidCsrfTokenManager, which
    // accepts any token unconditionally — a pre-existing, project-wide
    // convention with no exception path for asserting real CSRF rejection.

    /** @return array{0: \App\Entity\OAuthClient, 1: string} */
    private function createOAuthClient(?Organization $org): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: 'Consent Test App',
            grants: ['authorization_code', 'refresh_token'],
            scopes: ['api:read'],
            redirectUris: ['https://app.example.com/callback'],
            organization: $org,
        );
    }

    private function getCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
