<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'plans')]
#[ORM\UniqueConstraint(name: 'uniq_plan_slug', columns: ['slug'])]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true, length: 64)]
    private ?string $stripeProductId = null;

    #[ORM\Column(nullable: true, length: 64)]
    private ?string $stripePriceIdMonthly = null;

    #[ORM\Column(nullable: true, length: 64)]
    private ?string $stripePriceIdAnnual = null;

    /** Monthly price in cents (0 for free plans) */
    #[ORM\Column(type: 'integer')]
    private int $monthlyPriceCents = 0;

    /** Annual price in cents (0 for free plans) */
    #[ORM\Column(type: 'integer')]
    private int $annualPriceCents = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    private bool $isFree = false;

    /** When true, the pricing page shows "Contact us" instead of a price and checkout button. */
    #[ORM\Column(type: 'boolean')]
    private bool $isCustomQuote = false;

    /** When false, the plan is not shown on the public pricing page (used for private enterprise deals). */
    #[ORM\Column(type: 'boolean')]
    private bool $isPublic = true;

    /** Controls display order on the pricing page */
    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    /** @var Collection<int, PlanEntitlement> */
    #[ORM\OneToMany(targetEntity: PlanEntitlement::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $planEntitlements;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->planEntitlements = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function setStripeProductId(?string $stripeProductId): void
    {
        $this->stripeProductId = $stripeProductId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripePriceIdMonthly(): ?string
    {
        return $this->stripePriceIdMonthly;
    }

    public function setStripePriceIdMonthly(?string $stripePriceIdMonthly): void
    {
        $this->stripePriceIdMonthly = $stripePriceIdMonthly;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStripePriceIdAnnual(): ?string
    {
        return $this->stripePriceIdAnnual;
    }

    public function setStripePriceIdAnnual(?string $stripePriceIdAnnual): void
    {
        $this->stripePriceIdAnnual = $stripePriceIdAnnual;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getMonthlyPriceCents(): int
    {
        return $this->monthlyPriceCents;
    }

    public function setMonthlyPriceCents(int $monthlyPriceCents): void
    {
        $this->monthlyPriceCents = $monthlyPriceCents;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getAnnualPriceCents(): int
    {
        return $this->annualPriceCents;
    }

    public function setAnnualPriceCents(int $annualPriceCents): void
    {
        $this->annualPriceCents = $annualPriceCents;
        $this->updatedAt = new DateTimeImmutable();
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

    public function isFree(): bool
    {
        return $this->isFree;
    }

    public function setIsFree(bool $isFree): void
    {
        $this->isFree = $isFree;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isCustomQuote(): bool
    {
        return $this->isCustomQuote;
    }

    public function setIsCustomQuote(bool $isCustomQuote): void
    {
        $this->isCustomQuote = $isCustomQuote;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return Collection<int, PlanEntitlement> */
    public function getPlanEntitlements(): Collection
    {
        return $this->planEntitlements;
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
