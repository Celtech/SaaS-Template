<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\CspNonceRequestListener;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class CspNonceRequestListenerTest extends UnitTestCase
{
    private CspNonceRequestListener $listener;

    protected function setUp(): void
    {
        $this->listener = new CspNonceRequestListener();
    }

    #[Test]
    public function setsNonceAttributeOnMainRequest(): void
    {
        $request = new Request();
        $event = $this->makeRequestEvent($request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->__invoke($event);

        $this->assertNotEmpty($request->attributes->get('csp_nonce'));
    }

    #[Test]
    public function nonceIsAtLeast16Bytes(): void
    {
        $request = new Request();
        $event = $this->makeRequestEvent($request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener->__invoke($event);

        $nonce = $request->attributes->get('csp_nonce');
        // base64_decode gives 16 raw bytes → base64_encode gives 24 chars
        $this->assertGreaterThanOrEqual(24, \strlen((string) $nonce));
    }

    #[Test]
    public function nonceChangesOnEveryRequest(): void
    {
        $r1 = new Request();
        $r2 = new Request();

        $this->listener->__invoke($this->makeRequestEvent($r1, HttpKernelInterface::MAIN_REQUEST));
        $this->listener->__invoke($this->makeRequestEvent($r2, HttpKernelInterface::MAIN_REQUEST));

        $this->assertNotSame(
            $r1->attributes->get('csp_nonce'),
            $r2->attributes->get('csp_nonce'),
        );
    }

    #[Test]
    public function subRequestsAreIgnored(): void
    {
        $request = new Request();
        $event = $this->makeRequestEvent($request, HttpKernelInterface::SUB_REQUEST);

        $this->listener->__invoke($event);

        $this->assertNull($request->attributes->get('csp_nonce'));
    }

    private function makeRequestEvent(Request $request, int $requestType): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(KernelInterface::class),
            $request,
            $requestType,
        );
    }
}
