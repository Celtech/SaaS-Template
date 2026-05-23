<?php

declare(strict_types=1);

namespace App\Entity;

enum EntitlementType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Unlimited = 'unlimited';
}
