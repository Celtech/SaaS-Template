<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\Permission;
use App\Entity\User;
use App\Repository\UserRoleRepository;
use App\Security\OrganizationProviderInterface;
use App\Security\Voter\OrganizationVoter;
use App\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class OrganizationVoterTest extends UnitTestCase
{
    /** @var UserRoleRepository&Stub */
    private UserRoleRepository $userRoleRepo;
    /** @var OrganizationProviderInterface&Stub */
    private OrganizationProviderInterface $orgProvider;
    private OrganizationVoter $voter;

    protected function setUp(): void
    {
        $this->userRoleRepo = $this->createStub(UserRoleRepository::class);
        $this->orgProvider = $this->createStub(OrganizationProviderInterface::class);

        $this->voter = new OrganizationVoter($this->userRoleRepo, $this->orgProvider);
    }

    #[Test]
    public function supportsOrgPermissionAttribute(): void
    {
        $token = $this->makeToken(new User('u@example.com', 'U'));

        $result = $this->voter->vote($token, null, [Permission::OrgMembersInvite->value]);

        $this->assertNotSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function abstainOnAdminPermissionAttribute(): void
    {
        $token = $this->makeToken(new User('u@example.com', 'U'));

        $result = $this->voter->vote($token, null, [Permission::AdminUsersView->value]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function abstainOnUnknownAttribute(): void
    {
        $token = $this->makeToken(new User('u@example.com', 'U'));

        $result = $this->voter->vote($token, null, ['not.a.real.permission']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function deniesAccessWhenUserIsNotEntityUser(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, null, [Permission::OrgMembersView->value]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function deniesAccessWhenUserHasNoOrganization(): void
    {
        $user = new User('u@example.com', 'U');
        $this->orgProvider->method('getOrganization')->willReturn(null);

        $result = $this->voter->vote($this->makeToken($user), null, [Permission::OrgMembersView->value]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function grantsAccessWhenUserHasPermission(): void
    {
        $user = new User('u@example.com', 'U');
        $org = $this->makeOrg($user);

        $this->orgProvider->method('getOrganization')->willReturn($org);
        $this->userRoleRepo->method('userHasPermission')->willReturn(true);

        $result = $this->voter->vote($this->makeToken($user), null, [Permission::OrgMembersInvite->value]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function deniesAccessWhenUserLacksPermission(): void
    {
        $user = new User('u@example.com', 'U');
        $org = $this->makeOrg($user);

        $this->orgProvider->method('getOrganization')->willReturn($org);
        $this->userRoleRepo->method('userHasPermission')->willReturn(false);

        $result = $this->voter->vote($this->makeToken($user), null, [Permission::OrgSettingsManage->value]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function passesCorrectPermissionToRepository(): void
    {
        $user = new User('u@example.com', 'U');
        $org = $this->makeOrg($user);

        $this->orgProvider->method('getOrganization')->willReturn($org);

        $capturedPermission = null;
        $this->userRoleRepo->method('userHasPermission')
            ->willReturnCallback(static function (User $u, Permission $p) use (&$capturedPermission): bool {
                $capturedPermission = $p;

                return true;
            });

        $this->voter->vote($this->makeToken($user), null, [Permission::OrgMembersRemove->value]);

        $this->assertSame(Permission::OrgMembersRemove, $capturedPermission);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allOrgPermissions(): array
    {
        return array_reduce(
            Permission::cases(),
            static function (array $carry, Permission $p): array {
                if (str_starts_with($p->value, 'org.')) {
                    $carry[$p->value] = [$p->value];
                }

                return $carry;
            },
            [],
        );
    }

    #[Test]
    #[DataProvider('allOrgPermissions')]
    public function supportsAllOrgPermissions(string $attribute): void
    {
        $user = new User('u@example.com', 'U');
        $org = $this->makeOrg($user);

        $this->orgProvider->method('getOrganization')->willReturn($org);
        $this->userRoleRepo->method('userHasPermission')->willReturn(true);

        $result = $this->voter->vote($this->makeToken($user), null, [$attribute]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    private function makeToken(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function makeOrg(User $owner): Organization
    {
        $org = $this->createStub(Organization::class);
        $org->method('getId')->willReturn(Uuid::v7());

        return $org;
    }
}
