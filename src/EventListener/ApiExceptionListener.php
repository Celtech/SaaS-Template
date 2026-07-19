<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

/**
 * Renders every exception on /api/* routes as RFC 7807 Problem Details.
 *
 * Runs at a high priority and stops propagation so Symfony's per-firewall
 * security ExceptionListener (which would otherwise try to redirect an
 * AccessDeniedException to a login page) never gets a chance to run —
 * the api firewall is stateless and has no login page to redirect to.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class ApiExceptionListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        [$status, $title, $detail] = $this->describe($exception);

        $event->setResponse(new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));

        $event->stopPropagation();
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof AuthenticationException => [401, 'Unauthorized', $exception->getMessage()],
            // Symfony throws a generic AccessDeniedException (not an AuthenticationException) when
            // access_control denies an anonymous/unauthenticated request — there's no user to check
            // scopes against yet, so this is really a 401, not a 403.
            $exception instanceof AccessDeniedException => $this->security->getUser() === null
                ? [401, 'Unauthorized', 'Authentication is required to access this resource.']
                : [403, 'Forbidden', $exception->getMessage()],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
                $exception->getMessage(),
            ],
            default => [500, 'Internal Server Error', 'An unexpected error occurred.'],
        };
    }
}
