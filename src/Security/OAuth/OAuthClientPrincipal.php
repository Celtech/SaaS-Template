<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthClient;
use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Synthetic UserInterface for Client Credentials tokens.
 * Used when an access token has no user (M2M / server-to-server).
 */
final class OAuthClientPrincipal implements UserInterface
{
    public function __construct(private readonly OAuthClient $client)
    {
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUserIdentifier(): string
    {
        $id = $this->client->getClientId();
        if ($id === '') {
            throw new LogicException('OAuthClient has an empty clientId.');
        }

        return $id;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    public function eraseCredentials(): void
    {
    }
}
