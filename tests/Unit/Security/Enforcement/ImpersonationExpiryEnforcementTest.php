<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Enforcement;

use App\Entity\User;
use App\Security\Enforcement\ImpersonationExpiryEnforcement;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ImpersonationExpiryEnforcementTest extends UnitTestCase
{
    private ImpersonationExpiryEnforcement $enforcement;

    protected function setUp(): void
    {
        $this->enforcement = new ImpersonationExpiryEnforcement();
    }

    #[Test]
    public function shouldEnforceIsFalseWhenNotImpersonating(): void
    {
        $request = $this->requestWithSession();

        $this->assertFalse($this->enforcement->shouldEnforce(new User('u@example.com', 'U'), $request));
    }

    #[Test]
    public function shouldEnforceIsFalseWithinTheSixtyMinuteWindow(): void
    {
        $request = $this->requestWithSession([
            'started_at' => time() - 1800,
            'return_url' => '/admin/users/1',
        ]);

        $this->assertFalse($this->enforcement->shouldEnforce(new User('u@example.com', 'U'), $request));
    }

    #[Test]
    public function shouldEnforceIsTrueAfterSixtyMinutes(): void
    {
        $request = $this->requestWithSession([
            'started_at' => time() - 3601,
            'return_url' => '/admin/users/1',
        ]);

        $this->assertTrue($this->enforcement->shouldEnforce(new User('u@example.com', 'U'), $request));
    }

    #[Test]
    public function buildRedirectResponseTargetsReturnUrlWithSwitchUserExit(): void
    {
        $request = $this->requestWithSession([
            'started_at' => time() - 3601,
            'return_url' => '/admin/users/1',
        ]);

        $response = $this->enforcement->buildRedirectResponse($request);

        $this->assertSame('/admin/users/1?_switch_user=_exit', $response->getTargetUrl());
    }

    #[Test]
    public function buildRedirectResponseAppendsToExistingQueryString(): void
    {
        $request = $this->requestWithSession([
            'started_at' => time() - 3601,
            'return_url' => '/admin/users/1?tab=activity',
        ]);

        $response = $this->enforcement->buildRedirectResponse($request);

        $this->assertSame('/admin/users/1?tab=activity&_switch_user=_exit', $response->getTargetUrl());
    }

    #[Test]
    public function buildRedirectResponseFallsBackToRootWithoutReturnUrl(): void
    {
        $request = $this->requestWithSession(['started_at' => time() - 3601]);

        $response = $this->enforcement->buildRedirectResponse($request);

        $this->assertSame('/?_switch_user=_exit', $response->getTargetUrl());
    }

    #[Test]
    public function buildRedirectResponseFlagsTheSessionAsExpired(): void
    {
        $request = $this->requestWithSession([
            'started_at' => time() - 3601,
            'return_url' => '/admin/users/1',
        ]);

        $this->enforcement->buildRedirectResponse($request);

        $data = $request->getSession()->get('_impersonation');
        $this->assertIsArray($data);
        $this->assertTrue($data['expired']);
    }

    #[Test]
    public function requiresFullReauthenticationIsFalse(): void
    {
        $this->assertFalse($this->enforcement->requiresFullReauthentication());
    }

    /** @param array<string, mixed>|null $impersonationData */
    private function requestWithSession(?array $impersonationData = null): Request
    {
        $session = new Session(new MockArraySessionStorage());
        if ($impersonationData !== null) {
            $session->set('_impersonation', $impersonationData);
        }

        $request = new Request();
        $request->setSession($session);

        return $request;
    }
}
