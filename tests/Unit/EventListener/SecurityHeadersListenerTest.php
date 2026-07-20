<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\SecurityHeadersListener;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class SecurityHeadersListenerTest extends UnitTestCase
{
    private SecurityHeadersListener $listener;

    protected function setUp(): void
    {
        $this->listener = new SecurityHeadersListener();
    }

    #[Test]
    public function setsXFrameOptionsDeny(): void
    {
        $response = $this->dispatchMainRequest(new Request());

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    #[Test]
    public function setsXContentTypeOptionsNoSniff(): void
    {
        $response = $this->dispatchMainRequest(new Request());

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function setsReferrerPolicy(): void
    {
        $response = $this->dispatchMainRequest(new Request());

        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    #[Test]
    public function setsHsts(): void
    {
        $response = $this->dispatchMainRequest(new Request());

        $this->assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $response->headers->get('Strict-Transport-Security')
        );
    }

    #[Test]
    public function setsPermissionsPolicy(): void
    {
        $response = $this->dispatchMainRequest(new Request());

        $this->assertSame(
            'camera=(), microphone=(), geolocation=()',
            $response->headers->get('Permissions-Policy')
        );
    }

    #[Test]
    public function contentSecurityPolicyContainsSelfDefault(): void
    {
        $response = $this->dispatchMainRequest(new Request());
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", (string) $csp);
    }

    #[Test]
    public function contentSecurityPolicyAllowsDataScriptsForAssetMapperCssImports(): void
    {
        // Symfony AssetMapper imports CSS as a JS module (assets/app.js) which the importmap
        // resolves to an empty 'data:application/javascript,' placeholder — without 'data:'
        // here, that import fails and aborts the whole module graph before Stimulus bootstraps.
        $response = $this->dispatchMainRequest(new Request());
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' data:", (string) $csp);
    }

    #[Test]
    public function contentSecurityPolicyBlocksFrameAncestors(): void
    {
        $response = $this->dispatchMainRequest(new Request());
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", (string) $csp);
    }

    #[Test]
    public function contentSecurityPolicyRestrictsBaseUri(): void
    {
        $response = $this->dispatchMainRequest(new Request());
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("base-uri 'self'", (string) $csp);
    }

    #[Test]
    public function contentSecurityPolicyRestrictsFormAction(): void
    {
        $response = $this->dispatchMainRequest(new Request());
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("form-action 'self'", (string) $csp);
    }

    #[Test]
    public function subRequestsAreSkipped(): void
    {
        $request = new Request();
        $response = new Response();
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        $this->listener->__invoke($event);

        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function headersAreSetOnExistingResponse(): void
    {
        $response = new Response('<html></html>', 200);
        $this->dispatchMainRequest(new Request(), $response);

        $this->assertNotNull($response->headers->get('X-Frame-Options'));
    }

    private function dispatchMainRequest(Request $request, ?Response $response = null): Response
    {
        $response ??= new Response();
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->listener->__invoke($event);

        return $response;
    }
}
