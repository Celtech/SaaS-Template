<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRoleRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRoleRepository::class)]
#[ORM\Table(name: 'user_roles')]
#[ORM\Index(columns: ['user_id', 'context_id'], name: 'idx_user_roles_user_context')]
#[ORM\UniqueConstraint(name: 'uniq_user_role_context', columns: ['user_id', 'role_id', 'context_id'])]
class UserRole
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $contextId;

    #[ORM\Column]
    private DateTimeImmutable $assignedAt;

    public function __construct(User $user, Role $role, ?Uuid $contextId = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->role = $role;
        $this->contextId = $contextId;
        $this->assignedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getContextId(): ?Uuid
    {
        return $this->contextId;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
