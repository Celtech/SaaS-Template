<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Organization;
use App\Entity\User;
use App\Security\OrganizationProviderInterface;
use App\Service\EntitlementService;
use App\Tests\UnitTestCase;
use App\Twig\EntitlementExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;

final class EntitlementExtensionTest extends UnitTestCase
{
    /** @var EntitlementService&Stub */
    private EntitlementService $entitlementService;
    /** @var OrganizationProviderInterface&Stub */
    private OrganizationProviderInterface $orgProvider;
    private EntitlementExtension $extension;
    private Organization $org;

    protected function setUp(): void
    {
        $this->entitlementService = $this->createStub(EntitlementService::class);
        $this->orgProvider = $this->createStub(OrganizationProviderInterface::class);
        $this->extension = new EntitlementExtension($this->entitlementService, $this->orgProvider);
        $this->org = new Organization('Acme', new User('owner@example.com', 'Owner'));
    }

    #[Test]
    public function registersEntitlementAndEntitlementLimitFunctions(): void
    {
        $names = array_map(static fn ($f) => $f->getName(), $this->extension->getFunctions());

        $this->assertContains('entitlement', $names);
        $this->assertContains('entitlement_limit', $names);
    }

    #[Test]
    public function isEnabledReturnsFalseWhenNoCurrentOrg(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn(null);

        $this->assertFalse($this->extension->isEnabled('can_export'));
    }

    #[Test]
    public function isEnabledDelegatesToEntitlementService(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn($this->org);
        $this->entitlementService->method('isEnabled')->willReturn(true);

        $this->assertTrue($this->extension->isEnabled('can_export'));
    }

    #[Test]
    public function isEnabledReturnsFalseWhenServiceReturnsFalse(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn($this->org);
        $this->entitlementService->method('isEnabled')->willReturn(false);

        $this->assertFalse($this->extension->isEnabled('can_export'));
    }

    #[Test]
    public function limitReturnsZeroWhenNoCurrentOrg(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn(null);

        $this->assertSame(0, $this->extension->limit('max_seats'));
    }

    #[Test]
    public function limitDelegatesToEntitlementService(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn($this->org);
        $this->entitlementService->method('limit')->willReturn(10);

        $this->assertSame(10, $this->extension->limit('max_seats'));
    }

    #[Test]
    public function limitReturnsMinusOneForUnlimitedFromService(): void
    {
        $this->orgProvider->method('getOrganization')->willReturn($this->org);
        $this->entitlementService->method('limit')->willReturn(-1);

        $this->assertSame(-1, $this->extension->limit('max_seats'));
    }
}
