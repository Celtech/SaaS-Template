<?php

declare(strict_types=1);

namespace App\Entity;

enum RoleContext: string
{
    case Admin = 'admin';
    case Org = 'org';
}
