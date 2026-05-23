<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Organization;

interface OrganizationProviderInterface
{
    public function getOrganization(): ?Organization;
}
