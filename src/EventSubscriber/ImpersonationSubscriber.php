<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Component\Uid\Uuid;

final class ImpersonationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SecurityEvents::SWITCH_USER => 'onSwitchUser'];
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();
        $token = $event->getToken();

        if ($token instanceof SwitchUserToken) {
            $data = $request->getSession()->get('_impersonation', []);
            $sessionId = \is_array($data) && isset($data['session_id']) ? (string) $data['session_id'] : Uuid::v4()->toRfc4122();
            $reason = \is_array($data) && isset($data['reason']) ? (string) $data['reason'] : '';

            /** @var User $admin */
            $admin = $token->getOriginalToken()->getUser();
            /** @var User $targetUser */
            $targetUser = $event->getTargetUser();

            $this->auditLogger->logImpersonation(
                'started',
                $admin->getId()->toRfc4122(),
                $targetUser->getId()->toRfc4122(),
                $sessionId,
                $reason,
            );

            $request->getSession()->set('_impersonation_session_id', $sessionId);
        } else {
            // Exit — $event->getTargetUser() is the admin being restored
            $sessionId = $request->getSession()->get('_impersonation_session_id');
            $data = $request->getSession()->get('_impersonation', []);
            $targetUserId = \is_array($data) && isset($data['target_user_id']) ? (string) $data['target_user_id'] : null;
            $reason = \is_array($data) && ($data['expired'] ?? false) === true ? 'expired' : null;

            /** @var User $admin */
            $admin = $event->getTargetUser();

            if (\is_string($sessionId) && $targetUserId !== null) {
                $this->auditLogger->logImpersonation(
                    'ended',
                    $admin->getId()->toRfc4122(),
                    $targetUserId,
                    $sessionId,
                    $reason,
                );
            }

            $request->getSession()->remove('_impersonation');
            $request->getSession()->remove('_impersonation_session_id');
        }
    }
}
