<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Replaces the real CSRF token manager in the test environment so that:
 *  - CsrfTokenBadge in AppAuthenticator is properly resolved (login can succeed)
 *  - Symfony form CSRF fields validate without a real session
 *  - {{ csrf_token() }} Twig calls render without the token manager being unavailable
 *
 * Without this, framework.csrf_protection: false removes CsrfProtectionListener,
 * leaving CsrfTokenBadge permanently unresolved and silently rejecting every login.
 */
final class AlwaysValidCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'test-token');
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'test-token');
    }

    public function removeToken(string $tokenId): ?string
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return true;
    }
}
