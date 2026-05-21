<?php

declare(strict_types=1);

namespace App\Message\Mail;

final readonly class SendMailMessage
{
    public function __construct(
        public string $template,
        public string $to,
        public array $context = [],
    ) {
    }
}
