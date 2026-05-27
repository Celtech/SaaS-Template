<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\OAuth\OAuthClientPrincipal;
use App\Service\OAuth\TokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class OAuthTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly TokenService $tokenService)
    {
    }

    public function supports(Request $request): bool
    {
        $header = $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $plainToken = substr($request->headers->get('Authorization', ''), 7);

        if ($plainToken === '') {
            throw new CustomUserMessageAuthenticationException('Bearer token is missing.');
        }

        return new SelfValidatingPassport(
            new UserBadge($plainToken, function (string $rawToken) use ($request) {
                $accessToken = $this->tokenService->validateAccessToken($rawToken);

                if ($accessToken === null) {
                    throw new CustomUserMessageAuthenticationException('Invalid or expired token.');
                }

                // Attach token metadata to request for downstream use (scope checks, etc.).
                $request->attributes->set('_oauth_token', $accessToken);
                $request->attributes->set('_oauth_scopes', $accessToken->getScopes());

                $user = $accessToken->getUser();
                if ($user !== null) {
                    return $user;
                }

                // Client Credentials — no human user, return a client principal.
                return new OAuthClientPrincipal($accessToken->getClient());
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => 'invalid_token', 'error_description' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="api"'],
        );
    }
}
