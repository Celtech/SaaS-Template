<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(columns: ['stripe_subscription_id'], name: 'idx_subscription_stripe_id')]
#[ORM\Index(columns: ['stripe_customer_id'], name: 'idx_subscription_stripe_customer')]
class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Plan $plan;

    #[ORM\Column(type: 'string', enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeCustomerId = null;

    /** The Stripe price ID currently active on this subscription */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $currentPeriodStart = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(type: 'boolean')]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $canceledAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(Organization $organization, Plan $plan, SubscriptionStatus $status)
    {
        $this->id = Uuid::v7();
        $this->organization = $organization;
        $this->plan = $plan;
        $this->status = $status;
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

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): void
    {
        $this->plan = $plan;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): void
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): void
    {
        $this->stripeCustomerId = $stripeCustomerId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): void
    {
        $this->stripePriceId = $stripePriceId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getTrialEndsAt(): ?DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?DateTimeImmutable $trialEndsAt): void
    {
        $this->trialEndsAt = $trialEndsAt;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCurrentPeriodStart(): ?DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(?DateTimeImmutable $currentPeriodStart): void
    {
        $this->currentPeriodStart = $currentPeriodStart;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCurrentPeriodEnd(): ?DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?DateTimeImmutable $currentPeriodEnd): void
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): void
    {
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCanceledAt(): ?DateTimeImmutable
    {
        return $this->canceledAt;
    }

    public function setCanceledAt(?DateTimeImmutable $canceledAt): void
    {
        $this->canceledAt = $canceledAt;
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

    /**
     * Returns true when the org should have full application access.
     * Checks both Stripe status and that the current period hasn't ended
     * (covers cancel_at_period_end: access continues until period_end).
     */
    public function isAccessible(): bool
    {
        if (!$this->status->isAccessible()) {
            return false;
        }

        // If period end is set and passed, access is over regardless of status
        if ($this->currentPeriodEnd !== null && $this->currentPeriodEnd < new DateTimeImmutable()) {
            return false;
        }

        return true;
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    public function isPastDue(): bool
    {
        return $this->status->requiresPaymentUpdate();
    }
}
