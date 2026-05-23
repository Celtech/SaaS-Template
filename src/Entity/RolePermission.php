<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
class RolePermission
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'permissions')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $permissionKey;

    public function __construct(Role $role, Permission $permission)
    {
        $this->role = $role;
        $this->permissionKey = $permission->value;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getPermissionKey(): string
    {
        return $this->permissionKey;
    }

    public function getPermission(): ?Permission
    {
        return Permission::tryFrom($this->permissionKey);
    }
}
