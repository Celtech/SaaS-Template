<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final class CurrentOrganizationProvider
{
    public function __construct(private readonly Security $security)
    {
    }

    public function getOrganization(): ?Organization
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getOrganization();
    }
}
