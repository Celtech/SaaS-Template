<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Security\WebauthnTwoFactorProvider;
use App\Service\WebauthnService;
use App\Tests\UnitTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use stdClass;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class WebauthnTwoFactorProviderTest extends UnitTestCase
{
    /** @var WebauthnCredentialRepository&Stub */
    private WebauthnCredentialRepository $credentialRepo;
    /** @var SessionInterface&Stub */
    private SessionInterface $session;
    private WebauthnTwoFactorProvider $provider;

    protected function setUp(): void
    {
        $this->credentialRepo = $this->createStub(WebauthnCredentialRepository::class);
        $this->session = $this->createStub(SessionInterface::class);

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getSession')->willReturn($this->session);

        $this->provider = new WebauthnTwoFactorProvider(
            $this->credentialRepo,
            new WebauthnService('https://localhost', 'Test App'),
            $this->createStub(TwoFactorFormRendererInterface::class),
            $requestStack,
            $this->createStub(EntityManagerInterface::class),
        );
    }

    #[Test]
    public function beginAuthenticationReturnsFalseForNonEntityUser(): void
    {
        $nonEntityUser = $this->createStub(UserInterface::class);

        $context = $this->createStub(AuthenticationContextInterface::class);
        $context->method('getUser')->willReturn($nonEntityUser);

        $this->assertFalse($this->provider->beginAuthentication($context));
    }

    #[Test]
    public function beginAuthenticationReturnsFalseWhenUserHasNoCredentials(): void
    {
        $user = new User('no-keys@example.com', 'No Keys');

        $this->credentialRepo->method('countByUser')->willReturn(0);

        $context = $this->createStub(AuthenticationContextInterface::class);
        $context->method('getUser')->willReturn($user);

        $this->assertFalse($this->provider->beginAuthentication($context));
    }

    #[Test]
    public function beginAuthenticationReturnsTrueWhenUserHasCredentials(): void
    {
        $user = new User('has-keys@example.com', 'Has Keys');

        $this->credentialRepo->method('countByUser')->willReturn(2);

        $context = $this->createStub(AuthenticationContextInterface::class);
        $context->method('getUser')->willReturn($user);

        $this->assertTrue($this->provider->beginAuthentication($context));
    }

    #[Test]
    public function needsPreparationReturnsTrue(): void
    {
        $this->assertTrue($this->provider->needsPreparation());
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseForNonEntityUser(): void
    {
        $this->assertFalse($this->provider->validateAuthenticationCode(new stdClass(), '{"id":"abc"}'));
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseWhenNoSessionOptions(): void
    {
        $user = new User('user@example.com', 'User');

        $this->session->method('get')->willReturn(null);

        $this->assertFalse($this->provider->validateAuthenticationCode($user, '{"id":"abc123"}'));
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseForInvalidJson(): void
    {
        $user = new User('user@example.com', 'User');

        $this->session->method('get')->willReturn('{"rpId":"localhost"}');

        $this->assertFalse($this->provider->validateAuthenticationCode($user, 'not-valid-json{'));
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseWhenCredentialIdMissingFromPayload(): void
    {
        $user = new User('user@example.com', 'User');

        $this->session->method('get')->willReturn('{"rpId":"localhost"}');

        $this->assertFalse($this->provider->validateAuthenticationCode($user, '{"rawId":"abc","type":"public-key"}'));
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseWhenCredentialNotFoundInRepository(): void
    {
        $user = new User('user@example.com', 'User');

        $this->session->method('get')->willReturn('{"rpId":"localhost"}');
        $this->credentialRepo->method('findByCredentialId')->willReturn(null);

        $this->assertFalse($this->provider->validateAuthenticationCode($user, '{"id":"nonexistent-id"}'));
    }

    #[Test]
    public function validateAuthenticationCodeReturnsFalseWhenCredentialBelongsToDifferentUser(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $attacker = new User('attacker@example.com', 'Attacker');

        $credential = new WebauthnCredential($owner, 'Owners Key', 'cred-id-123', []);

        $this->session->method('get')->willReturn('{"rpId":"localhost"}');
        $this->credentialRepo->method('findByCredentialId')->willReturn($credential);

        $this->assertFalse($this->provider->validateAuthenticationCode($attacker, '{"id":"cred-id-123"}'));
    }
}
