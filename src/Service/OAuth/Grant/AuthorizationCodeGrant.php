<?php

declare(strict_types=1);

namespace App\Service\OAuth\Grant;

use App\Repository\OAuthAuthorizationCodeRepository;
use App\Service\OAuth\ClientCredentialsExtractor;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\PkceVerifier;
use App\Service\OAuth\TokenGenerator;
use App\Service\OAuth\TokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** RFC 6749 §4.1.3 + RFC 7636 (PKCE) — the token-exchange half of the Authorization Code flow. */
final class AuthorizationCodeGrant
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly TokenService $tokenService,
        private readonly ClientCredentialsExtractor $credentialsExtractor,
        private readonly OAuthAuthorizationCodeRepository $authorizationCodes,
        private readonly PkceVerifier $pkceVerifier,
        private readonly TokenGenerator $generator,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $plainCode = $request->request->getString('code');
        $redirectUri = $request->request->getString('redirect_uri');
        $codeVerifier = $request->request->getString('code_verifier');

        if ($plainCode === '' || $redirectUri === '' || $codeVerifier === '') {
            return $this->error('invalid_request', 'code, redirect_uri, and code_verifier are required.');
        }

        [$clientId, $clientSecret] = $this->credentialsExtractor->extract($request);

        if ($clientId === null) {
            return $this->error('invalid_client', 'client_id is required.', Response::HTTP_UNAUTHORIZED);
        }

        $client = $this->clientService->findByClientId($clientId);

        if ($client === null) {
            return $this->error('invalid_client', 'Invalid client.', Response::HTTP_UNAUTHORIZED);
        }

        if ($client->isConfidential()) {
            if ($clientSecret === null || $this->clientService->validateClientCredentials($clientId, $clientSecret) === null) {
                return $this->error('invalid_client', 'Invalid client credentials.', Response::HTTP_UNAUTHORIZED);
            }
        }

        if (!$client->supportsGrant('authorization_code')) {
            return $this->error('unauthorized_client', 'This client is not authorized for the authorization_code grant.');
        }

        $authorizationCode = $this->authorizationCodes->findByCodeHash($this->generator->hashToken($plainCode));

        if ($authorizationCode === null || !$authorizationCode->isActive()) {
            return $this->error('invalid_grant', 'Authorization code is invalid, expired, or already used.');
        }

        // A code minted for one client must never be redeemable by another.
        if (!$authorizationCode->getClient()->getId()->equals($client->getId())) {
            return $this->error('invalid_grant', 'Authorization code is invalid, expired, or already used.');
        }

        if (!hash_equals($authorizationCode->getRedirectUri(), $redirectUri)) {
            return $this->error('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        if (!$this->pkceVerifier->verify($codeVerifier, $authorizationCode->getCodeChallenge())) {
            return $this->error('invalid_grant', 'code_verifier does not match the code_challenge.');
        }

        // Single-use: mark consumed before issuing tokens so a retried/replayed
        // request against the same code can never succeed twice.
        $authorizationCode->markUsed();
        $this->authorizationCodes->save($authorizationCode, flush: true);

        [, , $plainAccess, $plainRefresh] = $this->tokenService->issueTokenPair(
            client: $client,
            user: $authorizationCode->getUser(),
            organization: $authorizationCode->getOrganization(),
            scopes: $authorizationCode->getScopes(),
            includeRefreshToken: $client->supportsGrant('refresh_token'),
        );

        $response = [
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => TokenService::accessTokenTtl(),
            'scope' => implode(' ', $authorizationCode->getScopes()),
        ];

        if ($plainRefresh !== null) {
            $response['refresh_token'] = $plainRefresh;
        }

        return new JsonResponse($response);
    }

    private function error(string $code, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code, 'error_description' => $description],
            $status,
        );
    }
}
