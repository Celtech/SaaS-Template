<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(Connection $connection, CacheInterface $cache): JsonResponse
    {
        $checks = [];
        $healthy = true;

        try {
            $connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (Throwable) {
            $checks['database'] = 'error';
            $healthy = false;
        }

        try {
            $cache->get('health_check_probe', static fn () => true);
            $checks['cache'] = 'ok';
        } catch (Throwable) {
            $checks['cache'] = 'error';
            $healthy = false;
        }

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'error', 'checks' => $checks],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
