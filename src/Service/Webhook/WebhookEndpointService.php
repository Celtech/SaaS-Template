<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Entity\Organization;
use App\Entity\WebhookEndpoint;
use App\Repository\WebhookEndpointRepository;
use App\Service\Audit\AuditLogger;

class WebhookEndpointService
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WebhookSecretCrypto $crypto,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param string[] $events
     *
     * @return array{WebhookEndpoint, string} entity, one-time plaintext secret
     */
    public function createEndpoint(Organization $organization, string $url, array $events, ?string $actorId = null): array
    {
        $plainSecret = $this->generateSecret();

        $endpoint = new WebhookEndpoint(
            organization: $organization,
            url: $url,
            secretCiphertext: $this->crypto->encrypt($plainSecret),
            displayHint: substr($plainSecret, -4),
            events: $events,
        );

        $this->endpoints->save($endpoint, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logWebhookEvent(
                'endpoint.created',
                $endpoint->getId()->toRfc4122(),
                newValue: ['url' => $url, 'events' => $events],
                actorId: $actorId,
            );
        }

        return [$endpoint, $plainSecret];
    }

    /** @param string[] $events */
    public function updateEndpoint(WebhookEndpoint $endpoint, string $url, array $events, bool $isActive, ?string $actorId = null): void
    {
        $endpoint->setUrl($url);
        $endpoint->setEvents($events);
        $endpoint->setIsActive($isActive);
        $this->endpoints->save($endpoint, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logWebhookEvent(
                'endpoint.updated',
                $endpoint->getId()->toRfc4122(),
                newValue: ['url' => $url, 'events' => $events, 'is_active' => $isActive],
                actorId: $actorId,
            );
        }
    }

    /** Returns the new plaintext secret (shown once). */
    public function regenerateSecret(WebhookEndpoint $endpoint, ?string $actorId = null): string
    {
        $plainSecret = $this->generateSecret();
        $endpoint->setSecret($this->crypto->encrypt($plainSecret), substr($plainSecret, -4));
        $this->endpoints->save($endpoint, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logWebhookEvent(
                'endpoint.secret_regenerated',
                $endpoint->getId()->toRfc4122(),
                actorId: $actorId,
            );
        }

        return $plainSecret;
    }

    public function deleteEndpoint(WebhookEndpoint $endpoint, ?string $actorId = null): void
    {
        $endpointId = $endpoint->getId()->toRfc4122();
        $url = $endpoint->getUrl();

        $this->endpoints->remove($endpoint, flush: true);

        if ($actorId !== null) {
            $this->auditLogger->logWebhookEvent(
                'endpoint.deleted',
                $endpointId,
                oldValue: ['url' => $url],
                actorId: $actorId,
            );
        }
    }

    public function getPlainSecret(WebhookEndpoint $endpoint): string
    {
        return $this->crypto->decrypt($endpoint->getSecretCiphertext());
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
