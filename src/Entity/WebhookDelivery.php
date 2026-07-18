<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(columns: ['status', 'next_attempt_at'], name: 'idx_webhook_deliveries_due')]
class WebhookDelivery
{
    /** Max delivery attempts before giving up (RFC-style exponential-ish backoff below). */
    public const MAX_ATTEMPTS = 5;

    /** Seconds to wait before each successive retry: 1min, 5min, 30min, 2hr, 12hr. */
    public const RETRY_BACKOFF_SECONDS = [60, 300, 1800, 7200, 43200];

    /** Response bodies are truncated at this length before storage. */
    public const RESPONSE_BODY_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class, inversedBy: 'deliveries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WebhookEndpoint $endpoint;

    #[ORM\Column(length: 100)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'string', enumType: WebhookDeliveryStatus::class)]
    private WebhookDeliveryStatus $status;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(nullable: true)]
    private ?int $lastResponseCode;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastResponseBody;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(WebhookEndpoint $endpoint, string $eventType, array $payload)
    {
        $this->id = Uuid::v7();
        $this->endpoint = $endpoint;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->status = WebhookDeliveryStatus::Pending;
        $this->attempts = 0;
        $this->nextAttemptAt = null;
        $this->lastResponseCode = null;
        $this->lastResponseBody = null;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEndpoint(): WebhookEndpoint
    {
        return $this->endpoint;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): WebhookDeliveryStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getLastResponseCode(): ?int
    {
        return $this->lastResponseCode;
    }

    public function getLastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function recordSuccess(int $responseCode, string $responseBody): void
    {
        ++$this->attempts;
        $this->status = WebhookDeliveryStatus::Success;
        $this->lastResponseCode = $responseCode;
        $this->lastResponseBody = substr($responseBody, 0, self::RESPONSE_BODY_MAX_LENGTH);
        $this->nextAttemptAt = null;
    }

    /** Records a failed attempt and schedules the next retry, or marks the delivery exhausted. */
    public function recordFailure(?int $responseCode, string $responseBody): void
    {
        ++$this->attempts;
        $this->lastResponseCode = $responseCode;
        $this->lastResponseBody = substr($responseBody, 0, self::RESPONSE_BODY_MAX_LENGTH);

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = WebhookDeliveryStatus::Exhausted;
            $this->nextAttemptAt = null;

            return;
        }

        $this->status = WebhookDeliveryStatus::Failed;
        // $this->attempts is 1..(MAX_ATTEMPTS-1) here (the >= MAX_ATTEMPTS case returned above),
        // which always indexes within the fixed-size backoff table.
        $delaySeconds = self::RETRY_BACKOFF_SECONDS[$this->attempts - 1];
        $this->nextAttemptAt = new DateTimeImmutable()->modify('+' . $delaySeconds . ' seconds');
    }

    public function isDue(): bool
    {
        if ($this->status !== WebhookDeliveryStatus::Failed) {
            return false;
        }

        return $this->nextAttemptAt !== null && $this->nextAttemptAt <= new DateTimeImmutable();
    }
}
