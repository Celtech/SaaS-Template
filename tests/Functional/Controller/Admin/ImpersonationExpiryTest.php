<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ImpersonationExpiryTest extends FunctionalTestCase
{
    #[Test]
    public function impersonationSessionOlderThanSixtyMinutesIsForciblyEnded(): void
    {
        $admin = $this->createSuperAdmin('expiry-admin@example.com');
        $target = $this->createVerifiedUser('expiry-target@example.com');

        $this->loginAsSuperAdminWithStepUpConfirmed($admin);

        $csrfToken = static::getContainer()->get('security.csrf.token_manager')
            ->getToken('impersonate_' . $target->getId())->getValue();

        $this->client->request('POST', '/admin/impersonate/' . $target->getId(), [
            'reason' => 'Investigating support ticket',
            '_token' => $csrfToken,
        ]);
        $this->client->followRedirect();

        $this->assertSame($target->getEmail(), $this->client->getRequest()->getSession()->get('_impersonation')['target_user_email'] ?? null);

        // Backdate the impersonation session past the 60-minute TTL.
        $session = $this->client->getRequest()->getSession();
        $data = $session->get('_impersonation');
        $data['started_at'] = time() - 3601;
        $session->set('_impersonation', $data);
        $session->save();

        $this->client->request('GET', '/');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('_switch_user=_exit', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->followRedirect();

        $this->assertFalse($this->client->getRequest()->getSession()->has('_impersonation'));
    }
}
