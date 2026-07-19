<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\OAuthAccessToken;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Idempotency-Key support for mutating /api/* requests (POST, PATCH, DELETE).
 *
 * Sending an `Idempotency-Key` header is opt-in per request, same as Stripe/PayPal:
 * absent header means no replay guarantee. When present, scoped per organization
 * (per ROADMAP: `idempotency:{org_id}:{idempotency_key}`) so two orgs reusing the
 * same client-generated key never collide.
 *
 * Runs at priority 4 — after the api firewall (8) and rate limiter (6), so
 * `_oauth_token` is already resolved.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4, method: 'onRequest')]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 4, method: 'onResponse')]
final class IdempotencyKeyListener
{
    private const MUTATING_METHODS = ['POST', 'PATCH', 'DELETE'];
    private const RESPONSE_TTL = 86400; // 24 hours
    private const LOCK_TTL = 30.0; // generous safety margin for slow requests

    public function __construct(
        #[Autowire(service: 'cache.idempotency')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')
            || !\in_array($request->getMethod(), self::MUTATING_METHODS, true)
        ) {
            return;
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return;
        }

        $token = $request->attributes->get('_oauth_token');
        if (!$token instanceof OAuthAccessToken) {
            return;
        }

        $organization = $token->getOrganization();
        if ($organization === null) {
            // No org context to scope the key to — proceed without idempotency
            // tracking rather than guessing at a shared/global scope.
            return;
        }

        $cacheKey = $this->cacheKey($organization->getId()->toRfc4122(), $idempotencyKey);

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            /** @var array{status: int, headers: array<string, string[]>, content: string} $stored */
            $stored = $item->get();
            $response = new Response($stored['content'], $stored['status'], $stored['headers']);
            $response->headers->set('Idempotent-Replayed', 'true');
            $event->setResponse($response);

            return;
        }

        $lock = $this->lockFactory->createLock($cacheKey, self::LOCK_TTL, autoRelease: false);

        if (!$lock->acquire()) {
            throw new ConflictHttpException('A request with this Idempotency-Key is already in progress.');
        }

        $request->attributes->set('_idempotency_lock', $lock);
        $request->attributes->set('_idempotency_cache_key', $cacheKey);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $lock = $request->attributes->get('_idempotency_lock');
        $cacheKey = $request->attributes->get('_idempotency_cache_key');

        if (!$lock instanceof LockInterface || !\is_string($cacheKey)) {
            return;
        }

        try {
            $response = $event->getResponse();

            // Never remember a server error as if it were a permanent, replayable
            // outcome — it may well be transient, and the client should be free to
            // retry with the same key and get a fresh attempt.
            if ($response->getStatusCode() < 500) {
                $item = $this->cache->getItem($cacheKey);
                $item->set([
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->all(),
                    'content' => (string) $response->getContent(),
                ]);
                $item->expiresAfter(self::RESPONSE_TTL);
                $this->cache->save($item);
            }
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(string $organizationId, string $idempotencyKey): string
    {
        // PSR-6 keys can't contain `{}()/\@:`, and a client-supplied key isn't
        // guaranteed to be safe, so hash rather than concatenate raw.
        return 'idempotency_' . hash('sha256', $organizationId . ':' . $idempotencyKey);
    }
}
