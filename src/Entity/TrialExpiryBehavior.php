<?php

declare(strict_types=1);

namespace App\Entity;

enum TrialExpiryBehavior: string
{
    case RequirePayment = 'require_payment';
    case DowngradeToFree = 'downgrade_to_free';
    case Cancel = 'cancel';
}
