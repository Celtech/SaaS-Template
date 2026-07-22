<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use App\Security\Enforcement\AdminStepUpEnforcement;
use App\Service\OrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class FunctionalTestCase extends WebTestCase
{
    use DatabaseTransactionTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->beginTransaction();

        // cache.rate_limiter is filesystem-backed in test env (see cache.yaml) so it
        // survives the DB transaction rollback below. Rate limiters keyed by a fixed
        // value across tests (e.g. client IP) would otherwise leak state between test
        // methods and produce flaky 429s unrelated to what the test is asserting.
        $container->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();
        parent::tearDown();
    }

    protected function createUnverifiedUser(
        string $email = 'unverified@example.com',
        string $password = 'Password123!',
        string $name = 'Test User',
    ): User {
        $user = new User($email, $name);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createVerifiedUser(
        string $email = 'verified@example.com',
        string $password = 'Password123!',
        string $name = 'Test User',
    ): User {
        $user = $this->createUnverifiedUser($email, $password, $name);
        $user->markEmailVerified();
        $this->em->flush();

        return $user;
    }

    protected function createUserWithOrg(
        string $email = 'verified@example.com',
        string $password = 'Password123!',
        string $name = 'Test User',
        string $orgName = 'Test Workspace',
    ): User {
        $user = $this->createVerifiedUser($email, $password, $name);
        $orgService = static::getContainer()->get(OrganizationService::class);
        $orgService->createForUser($user, $orgName);

        return $user;
    }

    /** 2FA/TOTP is required for admin accounts (Phase 7a) — enabled here so tests don't get redirected to setup. */
    protected function createSuperAdmin(
        string $email = 'super-admin@example.com',
        string $password = 'Password123!',
        string $name = 'Super Admin',
    ): User {
        // Every user gets a personal org (this project's uniform tenancy invariant) —
        // without one, OrgOnboardingSubscriber redirects every request to the wizard.
        $user = $this->createUserWithOrg($email, $password, $name);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->enableTotp('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        return $user;
    }

    /**
     * Logs in as a super admin and satisfies AdminStepUpEnforcement's freshness
     * check so subsequent /admin/* requests aren't bounced back to re-authenticate.
     */
    protected function loginAsSuperAdminWithStepUpConfirmed(User $admin): void
    {
        $this->client->loginUser($admin);
        $this->client->request('GET', '/');

        $session = $this->client->getRequest()->getSession();
        $session->set(AdminStepUpEnforcement::SESSION_KEY, time());
        $session->save();
    }
}
