<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\User;

use App\Entity\DataErasureRequest;
use App\Entity\UserSession;
use App\Enum\DataErasureStatus;
use App\Message\User\AnonymizeUserMessage;
use App\MessageHandler\User\AnonymizeUserHandler;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;

final class AnonymizeUserHandlerIntegrationTest extends FunctionalTestCase
{
    private AnonymizeUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = static::getContainer()->get(AnonymizeUserHandler::class);
    }

    #[Test]
    public function itRevokesAllActiveSessionsOnAnonymization(): void
    {
        $user = $this->createVerifiedUser('sessions@example.com');

        $session1 = new UserSession($user, hash('sha256', 'token-a'), '127.0.0.1', 'TestAgent/1.0');
        $session2 = new UserSession($user, hash('sha256', 'token-b'), '127.0.0.2', 'TestAgent/2.0');
        $this->em->persist($session1);
        $this->em->persist($session2);

        $request = new DataErasureRequest($user, 'test');
        $this->em->persist($request);
        $this->em->flush();

        ($this->handler)(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed1 = $this->em->find(UserSession::class, $session1->getId());
        $refreshed2 = $this->em->find(UserSession::class, $session2->getId());
        $this->assertNotNull($refreshed1);
        $this->assertNotNull($refreshed2);
        $this->assertNotNull($refreshed1->getRevokedAt(), 'Session 1 must be revoked');
        $this->assertNotNull($refreshed2->getRevokedAt(), 'Session 2 must be revoked');
    }

    #[Test]
    public function itDoesNotRevokeAlreadyRevokedSessions(): void
    {
        $user = $this->createVerifiedUser('prerevoked@example.com');

        $alreadyRevoked = new UserSession($user, hash('sha256', 'old-token'), '10.0.0.1', 'OldAgent/1.0');
        $alreadyRevoked->revoke();
        $this->em->persist($alreadyRevoked);
        $this->em->flush();

        // Snapshot the timestamp after the flush so we have the DB-persisted value
        $this->em->clear();
        $persisted = $this->em->find(UserSession::class, $alreadyRevoked->getId());
        $this->assertNotNull($persisted);
        $revokedAtBeforeHandler = $persisted->getRevokedAt();

        $user = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($user);
        $request = new DataErasureRequest($user);
        $this->em->persist($request);
        $this->em->flush();

        $handlerStartedAt = new DateTimeImmutable();

        ($this->handler)(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(UserSession::class, $alreadyRevoked->getId());
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->getRevokedAt());
        $this->assertLessThan(
            $handlerStartedAt,
            $refreshed->getRevokedAt(),
            'Already-revoked session timestamp must not be updated by the handler'
        );
        $this->assertEquals(
            $revokedAtBeforeHandler->format('Y-m-d H:i:s'),
            $refreshed->getRevokedAt()?->format('Y-m-d H:i:s'),
            'Already-revoked session timestamp must not change'
        );
    }

    #[Test]
    public function itAnonymizesPiiAndSoftDeletesUser(): void
    {
        $user = $this->createVerifiedUser('pii@example.com', 'Password123!', 'Real Name');

        $request = new DataErasureRequest($user, 'gdpr');
        $this->em->persist($request);
        $this->em->flush();

        ($this->handler)(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertStringEndsWith('@anonymized.invalid', $refreshed->getEmail());
        $this->assertStringStartsWith('deleted-', $refreshed->getEmail());
        $this->assertSame('Deleted User', $refreshed->getName());
        $this->assertTrue($refreshed->isDeleted());
        $this->assertNull($refreshed->getAvatarUrl());
    }

    #[Test]
    public function itSetsRequestStatusToCompleted(): void
    {
        $user = $this->createVerifiedUser('status@example.com');

        $request = new DataErasureRequest($user);
        $this->em->persist($request);
        $this->em->flush();

        ($this->handler)(new AnonymizeUserMessage($request->getId()->toRfc4122()));

        $this->em->clear();
        $refreshed = $this->em->find(DataErasureRequest::class, $request->getId());
        $this->assertNotNull($refreshed);
        $this->assertSame(DataErasureStatus::Completed, $refreshed->getStatus());
        $this->assertInstanceOf(DateTimeImmutable::class, $refreshed->getProcessedAt());
    }
}
