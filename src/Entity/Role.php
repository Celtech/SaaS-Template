<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description;

    #[ORM\Column(enumType: RoleContext::class, length: 20)]
    private RoleContext $context;

    #[ORM\Column]
    private bool $isSystem;

    /** @var Collection<int, RolePermission> */
    #[ORM\OneToMany(targetEntity: RolePermission::class, mappedBy: 'role', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $permissions;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, string $slug, RoleContext $context, bool $isSystem = false, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->context = $context;
        $this->isSystem = $isSystem;
        $this->description = $description;
        $this->permissions = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getContext(): RoleContext
    {
        return $this->context;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    /** @return Collection<int, RolePermission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function grantPermission(Permission $permission): void
    {
        $key = $permission->value;
        foreach ($this->permissions as $existing) {
            if ($existing->getPermissionKey() === $key) {
                return;
            }
        }
        $this->permissions->add(new RolePermission($this, $permission));
    }

    public function revokePermission(Permission $permission): void
    {
        foreach ($this->permissions as $existing) {
            if ($existing->getPermissionKey() === $permission->value) {
                $this->permissions->removeElement($existing);

                return;
            }
        }
    }

    public function hasPermission(Permission $permission): bool
    {
        foreach ($this->permissions as $rp) {
            if ($rp->getPermissionKey() === $permission->value) {
                return true;
            }
        }

        return false;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
