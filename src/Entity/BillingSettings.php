<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BillingSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BillingSettingsRepository::class)]
#[ORM\Table(name: 'billing_settings')]
class BillingSettings
{
    public const int SINGLETON_ID = 1;
    public const int DEFAULT_TRIAL_DAYS = 14;
    public const int DEFAULT_GRACE_PERIOD_DAYS = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: 'boolean')]
    private bool $trialEnabled = true;

    #[ORM\Column(type: 'integer')]
    private int $trialDays = self::DEFAULT_TRIAL_DAYS;

    #[ORM\Column(type: 'boolean')]
    private bool $freeTierEnabled = false;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Plan $freeTierPlan = null;

    #[ORM\Column(type: 'string', enumType: TrialExpiryBehavior::class)]
    private TrialExpiryBehavior $trialExpiryBehavior = TrialExpiryBehavior::RequirePayment;

    /** Days after a failed payment before hard-blocking background jobs */
    #[ORM\Column(type: 'integer')]
    private int $gracePeriodDays = self::DEFAULT_GRACE_PERIOD_DAYS;

    /** When true, Stripe Checkout is used at signup to collect a card before the trial starts. */
    #[ORM\Column(type: 'boolean')]
    private bool $requireCreditCard = false;

    /**
     * Slug of the plan given as a trial to users who sign up without a plan token.
     * Null means no automatic trial — users go straight to the free tier (or billing_plans if no free tier).
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $defaultTrialPlanSlug = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function isTrialEnabled(): bool
    {
        return $this->trialEnabled;
    }

    public function setTrialEnabled(bool $trialEnabled): void
    {
        $this->trialEnabled = $trialEnabled;
    }

    public function getTrialDays(): int
    {
        return $this->trialDays;
    }

    public function setTrialDays(int $trialDays): void
    {
        $this->trialDays = $trialDays;
    }

    public function isFreeTierEnabled(): bool
    {
        return $this->freeTierEnabled;
    }

    public function setFreeTierEnabled(bool $freeTierEnabled): void
    {
        $this->freeTierEnabled = $freeTierEnabled;
    }

    public function getFreeTierPlan(): ?Plan
    {
        return $this->freeTierPlan;
    }

    public function setFreeTierPlan(?Plan $freeTierPlan): void
    {
        $this->freeTierPlan = $freeTierPlan;
    }

    public function getTrialExpiryBehavior(): TrialExpiryBehavior
    {
        return $this->trialExpiryBehavior;
    }

    public function setTrialExpiryBehavior(TrialExpiryBehavior $trialExpiryBehavior): void
    {
        $this->trialExpiryBehavior = $trialExpiryBehavior;
    }

    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    public function setGracePeriodDays(int $gracePeriodDays): void
    {
        $this->gracePeriodDays = $gracePeriodDays;
    }

    public function isRequireCreditCard(): bool
    {
        return $this->requireCreditCard;
    }

    public function setRequireCreditCard(bool $requireCreditCard): void
    {
        $this->requireCreditCard = $requireCreditCard;
    }

    public function getDefaultTrialPlanSlug(): ?string
    {
        return $this->defaultTrialPlanSlug;
    }

    public function setDefaultTrialPlanSlug(?string $defaultTrialPlanSlug): void
    {
        $this->defaultTrialPlanSlug = $defaultTrialPlanSlug;
    }
}
