<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookEndpointRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookEndpointRepository::class)]
#[ORM\Table(name: 'webhook_endpoints')]
class WebhookEndpoint
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 2048)]
    private string $url;

    /** Libsodium-encrypted signing secret (base64). Never stored or logged in plaintext. */
    #[ORM\Column(type: 'text')]
    private string $secretCiphertext;

    /** Last 4 characters of the plaintext secret, shown in the UI so the endpoint stays identifiable. */
    #[ORM\Column(length: 4)]
    private string $displayHint;

    /** @var string[] WebhookEvent values this endpoint receives. */
    #[ORM\Column(type: 'json')]
    private array $events = [];

    #[ORM\Column]
    private bool $isActive = true;

    /** @var Collection<int, WebhookDelivery> */
    #[ORM\OneToMany(targetEntity: WebhookDelivery::class, mappedBy: 'endpoint', cascade: ['remove'])]
    private Collection $deliveries;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @param string[] $events */
    public function __construct(
        Organization $organization,
        string $url,
        string $secretCiphertext,
        string $displayHint,
        array $events,
    ) {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->url = $url;
        $this->secretCiphertext = $secretCiphertext;
        $this->displayHint = $displayHint;
        $this->events = $events;
        $this->deliveries = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getSecretCiphertext(): string
    {
        return $this->secretCiphertext;
    }

    public function setSecret(string $secretCiphertext, string $displayHint): void
    {
        $this->secretCiphertext = $secretCiphertext;
        $this->displayHint = $displayHint;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getDisplayHint(): string
    {
        return $this->displayHint;
    }

    /** @return string[] */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @param string[] $events */
    public function setEvents(array $events): void
    {
        $this->events = array_values($events);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function subscribesTo(string $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
