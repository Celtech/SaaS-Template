<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
class LogoutEventListener
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->auditLogger->logAuth('logout', $user->getId()->toRfc4122());
    }
}
