<?php

declare(strict_types=1);

namespace App\Enum;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Deleted => 'Deleted',
        };
    }
}
