<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use App\Entity\OAuthClient;
use App\Entity\OAuthDeviceCode;
use App\Entity\Organization;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\DeviceCodeService;
use App\Service\OAuth\TokenGenerator;
use App\Tests\FunctionalTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DeviceVerifyControllerTest extends FunctionalTestCase
{
    #[Test]
    public function verifyRequiresAuthentication(): void
    {
        $this->client->request('GET', '/oauth/device');

        $this->assertResponseRedirects('/auth/login');
    }

    #[Test]
    public function verifyRendersEntryFormWithoutCode(): void
    {
        $user = $this->createUserWithOrg('device-entry@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/device');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Connect a device');
    }

    #[Test]
    public function verifyRendersErrorForUnknownCode(): void
    {
        $user = $this->createUserWithOrg('device-unknown-code@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/device', ['user_code' => 'ZZZZ-ZZZZ']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'invalid or has expired');
    }

    #[Test]
    public function verifyRendersErrorForExpiredCode(): void
    {
        $user = $this->createUserWithOrg('device-expired-code@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        [, $userCode] = $this->createExpiredDeviceCode($client);
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/device', ['user_code' => $userCode]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'invalid or has expired');
    }

    #[Test]
    public function verifyRendersConsentScreenForValidCode(): void
    {
        $user = $this->createUserWithOrg('device-consent@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        [, $userCode] = $this->createPendingDeviceCode($client);
        $this->client->loginUser($user);

        $this->client->request('GET', '/oauth/device', ['user_code' => $userCode]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $client->getName());
        $this->assertSelectorTextContains('body', 'Read access to the API');
    }

    #[Test]
    public function decideApprovalMarksDeviceCodeApproved(): void
    {
        $user = $this->createUserWithOrg('device-approve@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        [$deviceCode, $userCode] = $this->createPendingDeviceCode($client);
        $this->client->loginUser($user);

        $this->client->request('POST', '/oauth/device/decide', [
            '_token' => $this->getCsrfToken('oauth_device_verify'),
            'user_code' => $userCode,
            'decision' => 'approve',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'is connected');

        $this->em->clear();
        $refreshed = $this->em->find(OAuthDeviceCode::class, $deviceCode->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isApproved());
        $this->assertNotNull($refreshed->getUser());
    }

    #[Test]
    public function decideDenialMarksDeviceCodeDenied(): void
    {
        $user = $this->createUserWithOrg('device-deny@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        [$deviceCode, $userCode] = $this->createPendingDeviceCode($client);
        $this->client->loginUser($user);

        $this->client->request('POST', '/oauth/device/decide', [
            '_token' => $this->getCsrfToken('oauth_device_verify'),
            'user_code' => $userCode,
            'decision' => 'deny',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'denied');

        $this->em->clear();
        $refreshed = $this->em->find(OAuthDeviceCode::class, $deviceCode->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isDenied());
    }

    #[Test]
    public function decideRejectsAlreadyDecidedCode(): void
    {
        $user = $this->createUserWithOrg('device-already-decided@example.com');
        [$client] = $this->createOAuthClient($user->getOrganization());
        [, $userCode] = $this->createPendingDeviceCode($client);
        $this->client->loginUser($user);

        $params = [
            '_token' => $this->getCsrfToken('oauth_device_verify'),
            'user_code' => $userCode,
            'decision' => 'approve',
        ];

        $this->client->request('POST', '/oauth/device/decide', $params);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/oauth/device/decide', $params);
        $this->assertResponseStatusCodeSame(404);
    }

    /** @return array{0: OAuthClient, 1: string} */
    private function createOAuthClient(?Organization $org): array
    {
        $this->assertNotNull($org);
        $clientService = static::getContainer()->get(ClientService::class);

        return $clientService->createClient(
            name: 'Device Test App',
            grants: ['urn:ietf:params:oauth:grant-type:device_code'],
            scopes: ['api:read'],
            organization: $org,
        );
    }

    /** @return array{0: OAuthDeviceCode, 1: string} entity, plain user_code */
    private function createPendingDeviceCode(OAuthClient $client): array
    {
        $deviceCodeService = static::getContainer()->get(DeviceCodeService::class);
        [$deviceCode, , $plainUserCode] = $deviceCodeService->issue($client, ['api:read']);

        return [$deviceCode, $plainUserCode];
    }

    /** @return array{0: OAuthDeviceCode, 1: string} entity, plain user_code */
    private function createExpiredDeviceCode(OAuthClient $client): array
    {
        $generator = static::getContainer()->get(TokenGenerator::class);
        $plainUserCode = $generator->generateUserCode();

        $deviceCode = new OAuthDeviceCode(
            deviceCodeHash: $generator->hashToken($generator->generateToken()),
            userCodeHash: $generator->hashToken(strtoupper($plainUserCode)),
            client: $client,
            scopes: ['api:read'],
            interval: 5,
            expiresAt: new DateTimeImmutable()->modify('-1 minute'),
        );
        $this->em->persist($deviceCode);
        $this->em->flush();

        return [$deviceCode, $plainUserCode];
    }

    private function getCsrfToken(string $tokenId): string
    {
        return static::getContainer()
            ->get(CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
    }
}
