<?php

declare(strict_types=1);

namespace App\Message\User;

final readonly class AnonymizeUserMessage
{
    public function __construct(
        public string $dataErasureRequestId,
    ) {
    }
}
