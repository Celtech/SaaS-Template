<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Permission;
use App\Entity\User;
use App\Repository\UserRoleRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, null>
 */
final class AdminVoter extends Voter
{
    public function __construct(private readonly UserRoleRepository $userRoleRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::tryFrom($attribute)?->name !== null
            && str_starts_with($attribute, 'admin.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $permission = Permission::tryFrom($attribute);

        if ($permission === null) {
            return false;
        }

        // Admin permissions have no org context (context_id = null)
        return $this->userRoleRepository->userHasPermission($user, $permission, null);
    }
}
