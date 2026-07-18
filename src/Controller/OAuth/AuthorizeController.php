<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\User;
use App\Repository\OAuthAuthorizationCodeRepository;
use App\Security\OAuth\OAuthScope;
use App\Service\Audit\AuditLogger;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\TokenGenerator;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RFC 6749 §3.1 — the user-facing half of the Authorization Code flow.
 *
 * redirect_uri is only trusted for error redirects once it has been matched
 * exactly against the client's registered URIs (RFC 6749 §3.1.2.3 /
 * §4.1.2.1) — anything wrong before that point renders an in-app error page
 * instead of redirecting, to avoid becoming an open redirect.
 */
#[IsGranted('ROLE_USER')]
final class AuthorizeController extends AbstractController
{
    private const CODE_TTL_SECONDS = 60;

    public function __construct(
        private readonly ClientService $clientService,
        private readonly OAuthAuthorizationCodeRepository $authorizationCodes,
        private readonly TokenGenerator $generator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/oauth/authorize', name: 'oauth_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $client = $this->clientService->findByClientId($request->query->getString('client_id'));

        if ($client === null) {
            return $this->renderError('Unknown client_id.');
        }

        $redirectUri = $request->query->getString('redirect_uri');

        if (!\in_array($redirectUri, $client->getRedirectUris(), true)) {
            return $this->renderError('redirect_uri is not registered for this application.');
        }

        $state = $request->query->getString('state');

        if ($request->query->getString('response_type') !== 'code') {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', $state);
        }

        if (!$client->supportsGrant('authorization_code')) {
            return $this->redirectWithError($redirectUri, 'unauthorized_client', $state);
        }

        $codeChallenge = $request->query->getString('code_challenge');

        if ($codeChallenge === '' || $request->query->getString('code_challenge_method') !== 'S256') {
            return $this->redirectWithError($redirectUri, 'invalid_request', $state);
        }

        $requestedScopes = $this->parseScopes($request->query->getString('scope'));

        if (!OAuthScope::validSubset($requestedScopes)) {
            return $this->redirectWithError($redirectUri, 'invalid_scope', $state);
        }

        $scopes = $requestedScopes !== [] ? $requestedScopes : $client->getAllowedScopes();

        if (!$client->scopesAreAllowed($scopes)) {
            return $this->redirectWithError($redirectUri, 'invalid_scope', $state);
        }

        return $this->render('oauth/authorize.html.twig', [
            'client' => $client,
            'scopeEnums' => array_map(static fn (string $s) => OAuthScope::from($s), $scopes),
            'redirectUri' => $redirectUri,
            'state' => $state,
            'codeChallenge' => $codeChallenge,
            'scopeParam' => implode(' ', $scopes),
        ]);
    }

    #[Route('/oauth/authorize', name: 'oauth_authorize_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('oauth_authorize', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $client = $this->clientService->findByClientId($request->request->getString('client_id'));
        $redirectUri = $request->request->getString('redirect_uri');

        if ($client === null || !\in_array($redirectUri, $client->getRedirectUris(), true)) {
            throw $this->createNotFoundException();
        }

        $state = $request->request->getString('state');

        /** @var User $user */
        $user = $this->getUser();

        if ($request->request->getString('decision') !== 'approve') {
            $this->auditLogger->logOAuthEvent(
                'authorization.denied',
                $client->getId()->toRfc4122(),
                'oauth_client',
                actorId: $user->getId()->toRfc4122(),
            );

            return $this->redirectWithError($redirectUri, 'access_denied', $state);
        }

        $scopes = $this->parseScopes($request->request->getString('scope'));
        $codeChallenge = $request->request->getString('code_challenge');

        if (!OAuthScope::validSubset($scopes) || !$client->scopesAreAllowed($scopes) || $codeChallenge === '') {
            return $this->redirectWithError($redirectUri, 'invalid_request', $state);
        }

        $plainCode = $this->generator->generateToken();
        $authorizationCode = new OAuthAuthorizationCode(
            codeHash: $this->generator->hashToken($plainCode),
            client: $client,
            user: $user,
            organization: $user->getOrganization(),
            scopes: $scopes,
            redirectUri: $redirectUri,
            codeChallenge: $codeChallenge,
            expiresAt: new DateTimeImmutable()->modify('+' . self::CODE_TTL_SECONDS . ' seconds'),
        );
        $this->authorizationCodes->save($authorizationCode, flush: true);

        $this->auditLogger->logOAuthEvent(
            'authorization.granted',
            $client->getId()->toRfc4122(),
            'oauth_client',
            newValue: ['scopes' => $scopes],
            actorId: $user->getId()->toRfc4122(),
        );

        $params = ['code' => $plainCode];
        if ($state !== '') {
            $params['state'] = $state;
        }

        return new RedirectResponse($this->appendQuery($redirectUri, $params));
    }

    /** @return string[] */
    private function parseScopes(string $scopeString): array
    {
        if ($scopeString === '') {
            return [];
        }

        return array_values(array_unique(array_filter(explode(' ', $scopeString))));
    }

    private function redirectWithError(string $redirectUri, string $error, string $state): RedirectResponse
    {
        $params = ['error' => $error];
        if ($state !== '') {
            $params['state'] = $state;
        }

        return new RedirectResponse($this->appendQuery($redirectUri, $params));
    }

    /** @param array<string, string> $params */
    private function appendQuery(string $uri, array $params): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params);
    }

    private function renderError(string $message): Response
    {
        return $this->render(
            'oauth/authorize_error.html.twig',
            ['message' => $message],
            new Response(status: Response::HTTP_BAD_REQUEST),
        );
    }
}
