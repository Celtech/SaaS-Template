<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\ImpersonationSubscriber;
use App\Service\Audit\AuditLogger;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

final class ImpersonationSubscriberTest extends UnitTestCase
{
    private AuditLogger&MockObject $auditLogger;
    private ImpersonationSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->subscriber = new ImpersonationSubscriber($this->auditLogger);
    }

    #[Test]
    public function onSwitchUserLogsStartedWithSessionIdAndReason(): void
    {
        $admin = new User('admin@example.com', 'Admin');
        $target = new User('target@example.com', 'Target');

        $session = new Session(new MockArraySessionStorage());
        $session->set('_impersonation', [
            'session_id' => 'session-123',
            'reason' => 'support ticket #42',
        ]);

        $request = new Request();
        $request->setSession($session);

        $originalToken = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $token = new SwitchUserToken($target, 'main', $target->getRoles(), $originalToken);

        $event = new SwitchUserEvent($request, $target, $token);

        $this->auditLogger->expects($this->once())
            ->method('logImpersonation')
            ->with(
                'started',
                $admin->getId()->toRfc4122(),
                $target->getId()->toRfc4122(),
                'session-123',
                'support ticket #42',
            );

        $this->subscriber->onSwitchUser($event);

        $this->assertSame('session-123', $session->get('_impersonation_session_id'));
    }

    #[Test]
    public function onSwitchUserExitLogsEndedWithNullReasonForManualExit(): void
    {
        $admin = new User('admin@example.com', 'Admin');

        $session = new Session(new MockArraySessionStorage());
        $session->set('_impersonation', ['target_user_id' => 'target-id']);
        $session->set('_impersonation_session_id', 'session-123');

        $request = new Request();
        $request->setSession($session);

        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $event = new SwitchUserEvent($request, $admin, $token);

        $this->auditLogger->expects($this->once())
            ->method('logImpersonation')
            ->with('ended', $admin->getId()->toRfc4122(), 'target-id', 'session-123', null);

        $this->subscriber->onSwitchUser($event);

        $this->assertFalse($session->has('_impersonation'));
        $this->assertFalse($session->has('_impersonation_session_id'));
    }

    #[Test]
    public function onSwitchUserExitLogsEndedWithExpiredReasonWhenFlagged(): void
    {
        $admin = new User('admin@example.com', 'Admin');

        $session = new Session(new MockArraySessionStorage());
        $session->set('_impersonation', ['target_user_id' => 'target-id', 'expired' => true]);
        $session->set('_impersonation_session_id', 'session-123');

        $request = new Request();
        $request->setSession($session);

        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $event = new SwitchUserEvent($request, $admin, $token);

        $this->auditLogger->expects($this->once())
            ->method('logImpersonation')
            ->with('ended', $admin->getId()->toRfc4122(), 'target-id', 'session-123', 'expired');

        $this->subscriber->onSwitchUser($event);
    }
}
