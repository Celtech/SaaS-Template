<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanEntitlementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanEntitlementRepository::class)]
#[ORM\Table(name: 'plan_entitlements')]
#[ORM\UniqueConstraint(name: 'uniq_plan_entitlement', columns: ['plan_id', 'entitlement_id'])]
class PlanEntitlement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Plan::class, inversedBy: 'planEntitlements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\ManyToOne(targetEntity: Entitlement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entitlement $entitlement;

    /**
     * Interpretation depends on entitlement type:
     * - boolean: "1" = enabled, "0" = disabled
     * - integer: numeric limit as string (e.g. "10")
     * - unlimited: "-1"
     */
    #[ORM\Column(length: 50)]
    private string $value;

    public function __construct(Plan $plan, Entitlement $entitlement, string $value)
    {
        $this->id = Uuid::v7();
        $this->plan = $plan;
        $this->entitlement = $entitlement;
        $this->value = $value;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getEntitlement(): Entitlement
    {
        return $this->entitlement;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public function getIntValue(): int
    {
        return (int) $this->value;
    }

    public function getBoolValue(): bool
    {
        return $this->value === '1';
    }

    public function isUnlimited(): bool
    {
        return $this->value === '-1';
    }
}
