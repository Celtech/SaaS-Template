<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\OAuthAccessToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Enforces per-client and per-org request limits on /api/* routes.
 *
 * Runs at priority 6 — after the `api` firewall (priority 8) has authenticated
 * the request and populated `_oauth_token`, but before controllers execute.
 * Both limits must pass independently; whichever is hit first produces the 429.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class ApiRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.api_client')]
        private readonly RateLimiterFactory $clientLimiter,
        #[Autowire(service: 'limiter.api_org')]
        private readonly RateLimiterFactory $orgLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $token = $request->attributes->get('_oauth_token');

        if (!$token instanceof OAuthAccessToken) {
            return;
        }

        $this->consumeOrThrow($this->clientLimiter->create($token->getClient()->getClientId()));

        $organization = $token->getOrganization();
        if ($organization !== null) {
            $this->consumeOrThrow($this->orgLimiter->create($organization->getId()->toRfc4122()));
        }
    }

    private function consumeOrThrow(LimiterInterface $limiter): void
    {
        $this->assertAccepted($limiter->consume());
    }

    private function assertAccepted(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException($retryAfter, 'Rate limit exceeded. Try again later.');
    }
}
