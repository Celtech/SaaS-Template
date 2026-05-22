<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
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
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->beginTransaction();
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
}
