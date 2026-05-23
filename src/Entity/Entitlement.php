<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntitlementRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EntitlementRepository::class)]
#[ORM\Table(name: 'entitlements')]
#[ORM\UniqueConstraint(name: 'uniq_entitlement_slug', columns: ['slug'])]
class Entitlement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', enumType: EntitlementType::class)]
    private EntitlementType $type;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $name, EntitlementType $type)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->type = $type;
        $this->createdAt = new DateTimeImmutable();
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
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getType(): EntitlementType
    {
        return $this->type;
    }

    public function setType(EntitlementType $type): void
    {
        $this->type = $type;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
