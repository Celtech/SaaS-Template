<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\OAuthClient;
use App\Entity\Organization;
use App\Repository\OAuthClientRepository;
use App\Service\Audit\AuditLogger;

class ClientService
{
    public function __construct(
        private readonly OAuthClientRepository $clients,
        private readonly TokenGenerator $generator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function findByClientId(string $clientId): ?OAuthClient
    {
        return $this->clients->findByClientId($clientId);
    }

    /**
     * Creates a new confidential OAuth client and returns both the entity and the
     * one-time plaintext secret. The secret is hashed before persistence.
     *
     * @param string[] $grants
     * @param string[] $scopes
     * @param string[] $redirectUris
     *
     * @return array{OAuthClient, string}
     */
    public function createClient(
        string $name,
        array $grants,
        array $scopes,
        array $redirectUris = [],
        ?Organization $organization = null,
        ?string $description = null,
        ?string $actorId = null,
    ): array {
        $clientId = $this->generator->generateClientId();
        $plainSecret = $this->generator->generateClientSecret();

        $client = new OAuthClient($clientId, $name, $organization);
        $client->setDescription($description);
        $client->setAllowedGrants($grants);
        $client->setAllowedScopes($scopes);
        $client->setRedirectUris($redirectUris);
        $client->setClientSecretHash($this->generator->hashToken($plainSecret));

        $this->clients->save($client, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logOAuthEvent(
                'client.created',
                $client->getId()->toRfc4122(),
                'oauth_client',
                newValue: ['name' => $name, 'grants' => $grants, 'scopes' => $scopes],
                actorId: $actorId,
            );
        }

        return [$client, $plainSecret];
    }

    /**
     * Rotates the client secret. Returns the new plaintext secret (shown once).
     */
    public function regenerateSecret(OAuthClient $client, ?string $actorId = null): string
    {
        $plainSecret = $this->generator->generateClientSecret();
        $client->setClientSecretHash($this->generator->hashToken($plainSecret));
        $this->clients->save($client, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logOAuthEvent(
                'client.secret_regenerated',
                $client->getId()->toRfc4122(),
                'oauth_client',
                actorId: $actorId,
            );
        }

        return $plainSecret;
    }

    public function deleteClient(OAuthClient $client, ?string $actorId = null): void
    {
        $clientId = $client->getId()->toRfc4122();
        $name = $client->getName();

        $this->clients->remove($client, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logOAuthEvent(
                'client.deleted',
                $clientId,
                'oauth_client',
                oldValue: ['name' => $name],
                actorId: $actorId,
            );
        }
    }

    public function validateClientCredentials(string $clientId, string $clientSecret): ?OAuthClient
    {
        $client = $this->clients->findByClientId($clientId);

        if ($client === null || !$client->isConfidential()) {
            return null;
        }

        $storedHash = $client->getClientSecretHash();
        if ($storedHash === null) {
            return null;
        }

        if (!$this->generator->verifySecret($clientSecret, $storedHash)) {
            return null;
        }

        return $client;
    }
}
