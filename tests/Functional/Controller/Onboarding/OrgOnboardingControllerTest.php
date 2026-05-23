<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Onboarding;

use App\Entity\UserRole;
use App\Repository\UserRoleRepository;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrgOnboardingControllerTest extends FunctionalTestCase
{
    #[Test]
    public function unauthenticatedUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/onboarding/org');

        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function verifiedUserWithoutOrgSeesFormWithDefaultName(): void
    {
        $user = $this->createVerifiedUser('no-org@example.com', 'Password123!', 'Alice Smith');
        $this->client->loginUser($user);
        $this->client->request('GET', '/onboarding/org');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="org_onboarding[name]"]');
        $this->assertInputValueSame('org_onboarding[name]', "Alice's Workspace");
    }

    #[Test]
    public function verifiedUserWithOrgIsRedirectedToDashboard(): void
    {
        $user = $this->createUserWithOrg('has-org@example.com');
        $this->client->loginUser($user);
        $this->client->request('GET', '/onboarding/org');

        $this->assertResponseRedirects('/');
    }

    #[Test]
    public function submittingEmptyNameShowsValidationError(): void
    {
        $user = $this->createVerifiedUser('empty-name@example.com');
        $this->client->loginUser($user);
        $this->client->request('POST', '/onboarding/org', [
            'org_onboarding' => ['name' => '', '_token' => 'test'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('.text-destructive');
    }

    #[Test]
    public function submittingValidNameCreatesOrgAndRedirects(): void
    {
        $user = $this->createVerifiedUser('new-org@example.com', 'Password123!', 'Bob Jones');
        $this->client->loginUser($user);
        $this->client->request('POST', '/onboarding/org', [
            'org_onboarding' => ['name' => 'Acme Inc.', '_token' => 'test'],
        ]);

        $this->assertResponseRedirects('/');

        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->getOrganization());
        $this->assertSame('Acme Inc.', $refreshed->getOrganization()->getName());
    }

    #[Test]
    public function submittingValidNameAssignsOwnerRoleToUser(): void
    {
        $user = $this->createVerifiedUser('owner-role@example.com');
        $this->client->loginUser($user);
        $this->client->request('POST', '/onboarding/org', [
            'org_onboarding' => ['name' => 'My Workspace', '_token' => 'test'],
        ]);

        $this->assertResponseRedirects('/');

        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $org = $refreshed->getOrganization();
        $this->assertNotNull($org);

        $userRoleRepo = static::getContainer()->get(UserRoleRepository::class);
        $roles = $userRoleRepo->findForUser($refreshed, $org->getId());
        $slugs = array_map(static fn (UserRole $ur) => $ur->getRole()->getSlug(), $roles);
        $this->assertContains('org-owner', $slugs);
    }
}
