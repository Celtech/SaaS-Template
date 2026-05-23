<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Profile;

use App\Entity\WebauthnCredential;
use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WebauthnSetupControllerTest extends FunctionalTestCase
{
    #[Test]
    public function registerRendersFormAndStoresOptionsInSession(): void
    {
        $user = $this->createUserWithOrg('webauthn-register@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/webauthn/register');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('optionsJson', $content);
    }

    #[Test]
    public function saveReturnsErrorFlashForEmptyName(): void
    {
        $user = $this->createUserWithOrg('webauthn-save-name@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/webauthn/register');
        $this->client->request('POST', '/profile/2fa/webauthn/register', [
            '_token' => 'test',
            'name' => '',
            'response' => '{"id":"abc123"}',
        ]);

        $this->assertResponseRedirects('/profile/2fa/webauthn/register');
        $this->client->followRedirect();
        $this->assertStringContainsString('Invalid request', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function saveReturnsErrorFlashForEmptyResponse(): void
    {
        $user = $this->createUserWithOrg('webauthn-save-resp@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/webauthn/register');
        $this->client->request('POST', '/profile/2fa/webauthn/register', [
            '_token' => 'test',
            'name' => 'My Key',
            'response' => '',
        ]);

        $this->assertResponseRedirects('/profile/2fa/webauthn/register');
        $this->client->followRedirect();
        $this->assertStringContainsString('Invalid request', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function saveReturnsErrorFlashWhenSessionOptionsAreMissing(): void
    {
        $user = $this->createUserWithOrg('webauthn-save-no-session@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/webauthn/register', [
            '_token' => 'test',
            'name' => 'My Key',
            'response' => '{"id":"abc123","type":"public-key"}',
        ]);

        $this->assertResponseRedirects('/profile/2fa/webauthn/register');
        $this->client->followRedirect();
        $this->assertStringContainsString('Registration session expired', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function saveReturnsErrorFlashWhenVerificationFails(): void
    {
        $user = $this->createUserWithOrg('webauthn-verify-fail@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/webauthn/register');
        $this->client->request('POST', '/profile/2fa/webauthn/register', [
            '_token' => 'test',
            'name' => 'My Key',
            'response' => '{"id":"bad-id","type":"public-key","rawId":"bad-id","response":{}}',
        ]);

        $this->assertResponseRedirects('/profile/2fa/webauthn/register');
        $this->client->followRedirect();
        $this->assertStringContainsString('Security key verification failed', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function removeReturns404ForNonExistentCredential(): void
    {
        $user = $this->createUserWithOrg('webauthn-remove-404@example.com');
        $this->client->loginUser($user);

        $fakeId = '01900000-0000-7000-8000-000000000000';
        $this->client->request('POST', '/profile/2fa/webauthn/' . $fakeId . '/remove', [
            '_token' => 'test',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function removeReturns404WhenCredentialBelongsToDifferentUser(): void
    {
        $owner = $this->createUserWithOrg('cred-owner@example.com');
        $attacker = $this->createUserWithOrg('cred-attacker@example.com');

        $credential = new WebauthnCredential($owner, 'Owners Key', 'cred-id-xyz', []);
        $this->em->persist($credential);
        $this->em->flush();

        $this->client->loginUser($attacker);
        $this->client->request('POST', '/profile/2fa/webauthn/' . $credential->getId()->toRfc4122() . '/remove', [
            '_token' => 'webauthn_remove_' . $credential->getId()->toRfc4122(),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function removeDeletesCredentialAndRedirectsWithSuccessFlash(): void
    {
        $user = $this->createUserWithOrg('webauthn-remove-ok@example.com');

        $credential = new WebauthnCredential($user, 'YubiKey', 'cred-id-remove', []);
        $this->em->persist($credential);
        $this->em->flush();

        $this->client->loginUser($user);
        $credId = $credential->getId()->toRfc4122();

        $this->client->request('POST', '/profile/2fa/webauthn/' . $credId . '/remove', [
            '_token' => 'webauthn_remove_' . $credId,
        ]);

        $this->assertResponseRedirects('/profile/security');
        $this->client->followRedirect();
        $this->assertStringContainsString('has been removed', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $this->assertNull($this->em->find(WebauthnCredential::class, $credential->getId()));
    }
}
