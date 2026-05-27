<?php

declare(strict_types=1);

namespace App\Security\OAuth;

enum OAuthScope: string
{
    case OpenId = 'openid';
    case Profile = 'profile';
    case Email = 'email';
    case OrgRead = 'org:read';
    case OrgWrite = 'org:write';
    case ApiRead = 'api:read';
    case ApiWrite = 'api:write';

    public function description(): string
    {
        return match ($this) {
            self::OpenId => 'Verify your identity',
            self::Profile => 'Read your name and avatar',
            self::Email => 'Read your email address',
            self::OrgRead => 'Read organization data',
            self::OrgWrite => 'Write organization data',
            self::ApiRead => 'Read access to the API',
            self::ApiWrite => 'Write access to the API',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @param string[] $values */
    public static function validSubset(array $values): bool
    {
        foreach ($values as $v) {
            if (self::tryFrom($v) === null) {
                return false;
            }
        }

        return true;
    }
}
