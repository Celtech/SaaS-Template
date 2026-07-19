<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Test-only mutating endpoint, wired only via config/routes.yaml's when@test
 * block — never registered outside the test environment. It exists purely so
 * IdempotencyKeyListenerTest can exercise Idempotency-Key handling through a
 * real HTTP request/response cycle: no production /api/v1/* endpoint mutates
 * anything yet, but the listener's behavior needs to be verified end-to-end
 * rather than by hand-constructing kernel events.
 *
 * Returns a fresh UUID on every real invocation, so a test can tell a replayed
 * response (same UUID) apart from a genuinely re-executed one (different UUID).
 */
final class IdempotencyTestController
{
    #[Route('/api/v1/_test/idempotency', name: 'test_idempotency_echo', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['invocation_id' => Uuid::v4()->toRfc4122()]);
    }
}
