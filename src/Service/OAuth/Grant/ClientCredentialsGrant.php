<?php

declare(strict_types=1);

namespace App\Service\OAuth\Grant;

use App\Security\OAuth\OAuthScope;
use App\Service\OAuth\ClientCredentialsExtractor;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\TokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClientCredentialsGrant
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly TokenService $tokenService,
        private readonly ClientCredentialsExtractor $credentialsExtractor,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        [$clientId, $clientSecret] = $this->credentialsExtractor->extract($request);

        if ($clientId === null || $clientSecret === null) {
            return $this->error('invalid_client', 'Client credentials are required.', Response::HTTP_UNAUTHORIZED);
        }

        $client = $this->clientService->validateClientCredentials($clientId, $clientSecret);

        if ($client === null) {
            return $this->error('invalid_client', 'Invalid client credentials.', Response::HTTP_UNAUTHORIZED);
        }

        if (!$client->supportsGrant('client_credentials')) {
            return $this->error('unauthorized_client', 'This client is not authorized for the client_credentials grant.');
        }

        $requestedScopes = $this->parseScopes($request->request->getString('scope'));

        if (!OAuthScope::validSubset($requestedScopes)) {
            return $this->error('invalid_scope', 'One or more requested scopes are invalid.');
        }

        // Fall back to client's full allowed scope if none requested.
        $scopes = $requestedScopes !== [] ? $requestedScopes : $client->getAllowedScopes();

        if (!$client->scopesAreAllowed($scopes)) {
            return $this->error('invalid_scope', 'One or more requested scopes are not allowed for this client.');
        }

        [, , $plainAccess, $plainRefresh] = $this->tokenService->issueTokenPair(
            client: $client,
            user: null,
            organization: $client->getOrganization(),
            scopes: $scopes,
            includeRefreshToken: $client->supportsGrant('refresh_token'),
        );

        $response = [
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => TokenService::accessTokenTtl(),
            'scope' => implode(' ', $scopes),
        ];

        if ($plainRefresh !== null) {
            $response['refresh_token'] = $plainRefresh;
        }

        return new JsonResponse($response);
    }

    /** @return string[] */
    private function parseScopes(string $scopeString): array
    {
        if ($scopeString === '') {
            return [];
        }

        return array_values(array_unique(array_filter(explode(' ', $scopeString))));
    }

    private function error(string $code, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code, 'error_description' => $description],
            $status,
        );
    }
}
