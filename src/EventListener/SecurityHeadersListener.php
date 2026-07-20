<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $nonce = $event->getRequest()->attributes->get('csp_nonce', '');
        $nonceDirective = $nonce !== '' ? " 'nonce-{$nonce}'" : '';

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $headers->set(
            'Content-Security-Policy',
            implode('; ', [
                "default-src 'self'",
                // 'data:' is required because Symfony AssetMapper imports CSS as a JS module
                // (see assets/app.js: `import './styles/app.css'`) and maps that specifier to
                // an empty 'data:application/javascript,' placeholder in the importmap — the
                // actual stylesheet is still applied via a normal <link rel="stylesheet">, this
                // placeholder is never anything but empty. Without it, that import fails to
                // resolve, which aborts the whole app.js module graph before it reaches
                // stimulus_bootstrap.js — breaking every Stimulus controller on the page,
                // including the dropdown menus.
                "script-src 'self' data:{$nonceDirective}",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ])
        );
    }
}
