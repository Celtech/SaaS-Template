<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\OAuthAccessToken;
use App\Security\OAuth\OAuthClientPrincipal;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Base for /api/v1 resource controllers.
 *
 * Success responses use a standard `{"data": ...}` envelope; errors are handled
 * centrally by ApiExceptionListener as RFC 7807 Problem Details, so controllers
 * should throw rather than build error responses themselves.
 */
abstract class ApiController extends AbstractController
{
    protected function apiData(mixed $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /** Access token attached to the request by OAuthTokenAuthenticator. */
    protected function oauthToken(Request $request): OAuthAccessToken
    {
        $token = $request->attributes->get('_oauth_token');

        if (!$token instanceof OAuthAccessToken) {
            throw new LogicException('No OAuthAccessToken on the request — is this route behind the api firewall?');
        }

        return $token;
    }

    /** @return string[] */
    protected function oauthScopes(Request $request): array
    {
        /** @var string[] $scopes */
        $scopes = $request->attributes->get('_oauth_scopes', []);

        return $scopes;
    }

    protected function denyAccessUnlessScope(Request $request, string $scope): void
    {
        if (!\in_array($scope, $this->oauthScopes($request), true)) {
            throw new AccessDeniedException("This action requires the '{$scope}' scope.");
        }
    }

    protected function isOAuthClientPrincipal(): bool
    {
        return $this->getUser() instanceof OAuthClientPrincipal;
    }
}
