<?php

declare(strict_types=1);

namespace App\Enum;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
