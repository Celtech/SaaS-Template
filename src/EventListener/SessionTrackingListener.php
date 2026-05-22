<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
class SessionTrackingListener
{
    private const SESSION_KEY = '_tracked_session_id';
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserSessionRepository $sessionRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return;
        }

        $sessionId = $session->getId();
        $tokenHash = hash('sha256', $sessionId);
        $trackedId = $session->get(self::SESSION_KEY);

        if ($trackedId === null) {
            $existing = $this->sessionRepository->findByTokenHash($tokenHash);

            if ($existing === null) {
                $userSession = new UserSession(
                    $user,
                    $tokenHash,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );
                $this->em->persist($userSession);
                $this->em->flush();
                $session->set(self::SESSION_KEY, $userSession->getId()->toRfc4122());
            } else {
                $session->set(self::SESSION_KEY, $existing->getId()->toRfc4122());
            }

            return;
        }

        // Periodically update last_active_at to avoid flushing on every request
        $userSession = $this->sessionRepository->findByTokenHash($tokenHash);
        if ($userSession === null) {
            return;
        }

        $secondsSinceTouched = (new \DateTimeImmutable())->getTimestamp()
            - $userSession->getLastActiveAt()->getTimestamp();

        if ($secondsSinceTouched >= self::TOUCH_INTERVAL_SECONDS) {
            $userSession->touch();
            $this->em->flush();
        }
    }
}
