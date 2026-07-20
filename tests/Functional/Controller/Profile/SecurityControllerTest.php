<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Profile;

use App\Entity\User;
use App\Entity\UserSession;
use App\Message\Notification\SendNotificationMessage;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SecurityControllerTest extends FunctionalTestCase
{
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->transport = $transport;
        $this->transport->reset();
    }

    #[Test]
    public function revokingASessionNotifiesTheUser(): void
    {
        $user = $this->createUserWithOrg('revoke-session-notify@example.com');
        $session = new UserSession($user, 'other-session-hash', '203.0.113.5', 'other-agent');
        $this->em->persist($session);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/sessions/' . $session->getId()->toRfc4122() . '/revoke', [
            '_token' => $this->csrfToken('profile_sessions'),
        ]);

        $this->assertResponseRedirects('/profile/security');

        // security.session_revoked defaults to both in_app and email.
        $sent = $this->notificationMessagesFor($user);
        $this->assertCount(2, $sent);
        $this->assertSame('security.session_revoked', $sent[0]->type);
    }

    #[Test]
    public function revokingAllSessionsNotifiesWhenThereWereOthersToRevoke(): void
    {
        $user = $this->createUserWithOrg('revoke-all-notify@example.com');
        $this->em->persist(new UserSession($user, 'other-session-hash-2', '203.0.113.6', 'other-agent-2'));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/sessions/revoke-all', [
            '_token' => $this->csrfToken('profile_sessions'),
        ]);

        $this->assertResponseRedirects('/profile/security');

        $sent = $this->notificationMessagesFor($user);
        $this->assertCount(2, $sent);
        $this->assertSame('security.session_revoked', $sent[0]->type);
    }

    #[Test]
    public function revokingAllSessionsWithNoOtherActiveSessionsDoesNotNotify(): void
    {
        $user = $this->createUserWithOrg('revoke-all-noop-notify@example.com');

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/sessions/revoke-all', [
            '_token' => $this->csrfToken('profile_sessions'),
        ]);

        $this->assertResponseRedirects('/profile/security');
        $this->assertCount(0, $this->notificationMessagesFor($user));
    }

    /** @return SendNotificationMessage[] */
    private function notificationMessagesFor(User $user): array
    {
        $messages = [];

        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendNotificationMessage && $message->userId === $user->getId()->toRfc4122()) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function csrfToken(string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }
}
