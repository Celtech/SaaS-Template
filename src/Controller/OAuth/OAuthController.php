<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Security\OAuth\OAuthScope;
use App\Service\OAuth\ClientCredentialsExtractor;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\Grant\AuthorizationCodeGrant;
use App\Service\OAuth\Grant\ClientCredentialsGrant;
use App\Service\OAuth\TokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('')]
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientCredentialsGrant $clientCredentialsGrant,
        private readonly AuthorizationCodeGrant $authorizationCodeGrant,
        private readonly TokenService $tokenService,
        private readonly ClientService $clientService,
        private readonly ClientCredentialsExtractor $credentialsExtractor,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * RFC 8414 — Authorization Server Metadata.
     * Allows clients to auto-discover endpoints and capabilities.
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_discovery', methods: ['GET'])]
    public function discovery(): JsonResponse
    {
        $suffix = '/.well-known/oauth-authorization-server';
        $full = $this->router->generate('oauth_discovery', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $base = str_ends_with($full, $suffix) ? substr($full, 0, -\strlen($suffix)) : $full;

        return new JsonResponse([
            'issuer' => $base,
            'token_endpoint' => $base . '/oauth/token',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'introspection_endpoint' => $base . '/oauth/introspect',
            'authorization_endpoint' => $base . '/oauth/authorize',
            'device_authorization_endpoint' => $base . '/oauth/device/authorization',
            'scopes_supported' => OAuthScope::values(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => [
                'authorization_code',
                'client_credentials',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    /** RFC 6749 §3.2 — Token endpoint. Dispatches to the appropriate grant handler. */
    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $grantType = $request->request->getString('grant_type');

        return match ($grantType) {
            'client_credentials' => $this->clientCredentialsGrant->handle($request),
            'authorization_code' => $this->authorizationCodeGrant->handle($request),
            'refresh_token' => $this->handleRefreshToken($request),
            default => new JsonResponse(
                ['error' => 'unsupported_grant_type', 'error_description' => "Grant type '{$grantType}' is not supported."],
                Response::HTTP_BAD_REQUEST,
            ),
        };
    }

    /** RFC 7009 — Token revocation. */
    #[Route('/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): JsonResponse
    {
        $token = $request->request->getString('token');
        $tokenHint = $request->request->getString('token_type_hint');

        if ($token === '') {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'token is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Per RFC 7009 §2.2, revocation always returns 200 even for unknown tokens.
        if ($tokenHint === 'refresh_token') {
            $this->tokenService->revokeRefreshToken($token);
        } else {
            $this->tokenService->revokeAccessToken($token);
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }

    /** RFC 7662 — Token introspection. */
    #[Route('/oauth/introspect', name: 'oauth_introspect', methods: ['POST'])]
    public function introspect(Request $request): JsonResponse
    {
        $plainToken = $request->request->getString('token');

        if ($plainToken === '') {
            return new JsonResponse(['active' => false]);
        }

        $token = $this->tokenService->validateAccessToken($plainToken);

        if ($token === null) {
            return new JsonResponse(['active' => false]);
        }

        $response = [
            'active' => true,
            'scope' => implode(' ', $token->getScopes()),
            'client_id' => $token->getClient()->getClientId(),
            'exp' => $token->getExpiresAt()->getTimestamp(),
            'iat' => $token->getCreatedAt()->getTimestamp(),
        ];

        if ($token->getUser() !== null) {
            $response['sub'] = $token->getUser()->getId()->toRfc4122();
        }

        if ($token->getOrganization() !== null) {
            $response['org_id'] = $token->getOrganization()->getId()->toRfc4122();
        }

        return new JsonResponse($response);
    }

    private function handleRefreshToken(Request $request): JsonResponse
    {
        $plainRefresh = $request->request->getString('refresh_token');

        if ($plainRefresh === '') {
            return new JsonResponse(
                ['error' => 'invalid_request', 'error_description' => 'refresh_token is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // RFC 6749 §6 — the client must (re-)authenticate on every token-endpoint request.
        [$clientId, $clientSecret] = $this->credentialsExtractor->extract($request);

        if ($clientId === null || $clientSecret === null) {
            return new JsonResponse(
                ['error' => 'invalid_client', 'error_description' => 'Client credentials are required.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $client = $this->clientService->validateClientCredentials($clientId, $clientSecret);

        if ($client === null) {
            return new JsonResponse(
                ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (!$client->supportsGrant('refresh_token')) {
            return new JsonResponse(
                ['error' => 'unauthorized_client', 'error_description' => 'This client is not authorized for the refresh_token grant.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->tokenService->rotateRefreshToken($plainRefresh, $client);

        if ($result === null) {
            return new JsonResponse(
                ['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid, expired, or revoked.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        [, , $plainAccess, $newPlainRefresh] = $result;

        return new JsonResponse([
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => TokenService::accessTokenTtl(),
            'refresh_token' => $newPlainRefresh,
        ]);
    }
}
