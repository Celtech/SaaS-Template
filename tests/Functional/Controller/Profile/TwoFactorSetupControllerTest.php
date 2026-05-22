<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Profile;

use App\Tests\FunctionalTestCase;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;

final class TwoFactorSetupControllerTest extends FunctionalTestCase
{
    #[Test]
    public function setupRedirectsToSecurityIfTotpAlreadyEnabled(): void
    {
        $user = $this->createVerifiedUser('totp-on@example.com');
        $user->enableTotp('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/2fa/setup');

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function setupRendersQrCodeAndSecretForFreshUser(): void
    {
        $user = $this->createVerifiedUser('fresh-2fa@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/setup');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Set up two-factor authentication', $content);
    }

    #[Test]
    public function setupReusesSessionSecretOnSubsequentLoads(): void
    {
        $user = $this->createVerifiedUser('reuse-secret@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/setup');
        $secretFirst = trim((string) $this->client->getCrawler()->filter('code')->first()->text());

        $this->client->request('GET', '/profile/2fa/setup');
        $secretSecond = trim((string) $this->client->getCrawler()->filter('code')->first()->text());

        $this->assertSame($secretFirst, $secretSecond, 'Secret must be reused within the same session');
    }

    #[Test]
    public function enableRedirectsToSecurityIfTotpAlreadyEnabled(): void
    {
        $user = $this->createVerifiedUser('already-on@example.com');
        $user->enableTotp('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/2fa/enable', ['_token' => 'test', 'code' => '000000']);

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function enableRedirectsToSetupWhenSessionSecretIsMissing(): void
    {
        $user = $this->createVerifiedUser('no-secret@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/enable', ['_token' => 'test', 'code' => '123456']);

        $this->assertResponseRedirects('/profile/2fa/setup');
    }

    #[Test]
    public function enableFlashesErrorForInvalidCode(): void
    {
        $user = $this->createVerifiedUser('bad-code@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/setup');
        $this->client->request('POST', '/profile/2fa/enable', ['_token' => 'test', 'code' => '000000']);

        $this->assertResponseRedirects('/profile/2fa/setup');
        $this->client->followRedirect();
        $this->assertStringContainsString('Invalid code', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function enableActivatesTotpAndRedirectsToBackupCodes(): void
    {
        $user = $this->createVerifiedUser('enable-ok@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/setup');
        $secret = trim((string) $this->client->getCrawler()->filter('code')->first()->text());
        \assert($secret !== '');
        $code = TOTP::createFromSecret($secret)->now();

        $this->client->request('POST', '/profile/2fa/enable', ['_token' => 'test', 'code' => $code]);

        $this->assertResponseRedirects('/profile/2fa/backup-codes');
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isTotpAuthenticationEnabled());
    }

    #[Test]
    public function backupCodesPageShowsGeneratedCodesAfterEnable(): void
    {
        $user = $this->createVerifiedUser('backup-display@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/setup');
        $secret = trim((string) $this->client->getCrawler()->filter('code')->first()->text());
        \assert($secret !== '');
        $code = TOTP::createFromSecret($secret)->now();

        $this->client->request('POST', '/profile/2fa/enable', ['_token' => 'test', 'code' => $code]);
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('backup', strtolower($content));
    }

    #[Test]
    public function backupCodesPageShowsEmptyListWhenNoCodesInSession(): void
    {
        $user = $this->createVerifiedUser('no-backup@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/2fa/backup-codes');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function disableRedirectsToSecurityIfTotpNotEnabled(): void
    {
        $user = $this->createVerifiedUser('disable-off@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/disable', ['_token' => 'test', 'code' => '000000']);

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function disableFlashesErrorForInvalidCode(): void
    {
        $user = $this->createVerifiedUser('disable-bad@example.com');
        $user->enableTotp('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/2fa/disable', ['_token' => 'test', 'code' => '000000']);

        $this->assertResponseRedirects('/profile/security');
        $this->client->followRedirect();
        $this->assertStringContainsString('Invalid code', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function disableDeactivatesTotpAndRedirectsWithSuccessFlash(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->createVerifiedUser('disable-ok@example.com');
        $user->enableTotp($secret);
        $this->em->flush();

        $this->client->loginUser($user);
        $code = TOTP::createFromSecret($secret)->now();

        $this->client->request('POST', '/profile/2fa/disable', ['_token' => 'test', 'code' => $code]);

        $this->assertResponseRedirects('/profile/security');
        $this->client->followRedirect();
        $this->assertStringContainsString('two-factor authentication has been disabled', (string) $this->client->getResponse()->getContent());
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertFalse($refreshed->isTotpAuthenticationEnabled());
    }

    #[Test]
    public function enableEmailRedirectsIfAlreadyEnabled(): void
    {
        $user = $this->createVerifiedUser('email-on@example.com');
        $user->enableEmailAuth();
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/2fa/email/enable', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function enableEmailActivatesEmailAuthAndRedirectsWithSuccessFlash(): void
    {
        $user = $this->createVerifiedUser('email-enable@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/email/enable', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/security');
        $this->client->followRedirect();
        $this->assertStringContainsString('Email code two-factor authentication has been enabled', (string) $this->client->getResponse()->getContent());
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->isEmailAuthEnabled());
    }

    #[Test]
    public function disableEmailRedirectsIfNotEnabled(): void
    {
        $user = $this->createVerifiedUser('email-disable-off@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/email/disable', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function disableEmailDeactivatesEmailAuthAndRedirectsWithSuccessFlash(): void
    {
        $user = $this->createVerifiedUser('email-disable@example.com');
        $user->enableEmailAuth();
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/2fa/email/disable', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/security');
        $this->client->followRedirect();
        $this->assertStringContainsString('Email code two-factor authentication has been disabled', (string) $this->client->getResponse()->getContent());
        $refreshed = $this->em->find(\App\Entity\User::class, $user->getId());
        $this->assertNotNull($refreshed);
        $this->assertFalse($refreshed->isEmailAuthEnabled());
    }

    #[Test]
    public function regenerateBackupCodesRedirectsIfTotpNotEnabled(): void
    {
        $user = $this->createVerifiedUser('regen-off@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/2fa/backup-codes/regenerate', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/security');
    }

    #[Test]
    public function regenerateBackupCodesGeneratesNewCodesAndRedirects(): void
    {
        $user = $this->createVerifiedUser('regen-ok@example.com');
        $user->enableTotp('JBSWY3DPEHPK3PXP');
        $user->generateBackupCodes();
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/2fa/backup-codes/regenerate', ['_token' => 'test']);

        $this->assertResponseRedirects('/profile/2fa/backup-codes');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }
}
