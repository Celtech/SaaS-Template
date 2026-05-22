<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\WebauthnCredentialRepository;
use App\Service\WebauthnService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => 'webauthn'])]
final class WebauthnTwoFactorProvider implements TwoFactorProviderInterface
{
    public function __construct(
        private readonly WebauthnCredentialRepository $credentialRepo,
        private readonly WebauthnService $webauthnService,
        private readonly WebauthnFormRenderer $formRenderer,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();

        return $user instanceof User && $this->credentialRepo->countByUser($user) > 0;
    }

    public function needsPreparation(): bool
    {
        return true;
    }

    public function prepareAuthentication(object $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $credentials = $this->credentialRepo->findByUser($user);
        $credentialIds = array_map(
            static fn ($c) => $c->getCredentialId(),
            $credentials,
        );

        $options = $this->webauthnService->createAssertionOptions($credentialIds);
        $optionsJson = $this->webauthnService->serializeOptions($options);

        $this->requestStack->getSession()->set('_webauthn_assertion_options', $optionsJson);
    }

    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        try {
            $optionsJson = $this->requestStack->getSession()->get('_webauthn_assertion_options');
            if (!\is_string($optionsJson)) {
                return false;
            }

            $responseData = json_decode($authenticationCode, true, 512, \JSON_THROW_ON_ERROR);
            $credentialId = $responseData['id'] ?? null;
            if (!\is_string($credentialId)) {
                return false;
            }

            $entity = $this->credentialRepo->findByCredentialId($credentialId);
            if ($entity === null || $entity->getUser()->getId() !== $user->getId()) {
                return false;
            }

            $credentialRecord = $this->webauthnService->deserializeCredentialRecord(
                $entity->getCredentialSource()
            );

            $updatedRecord = $this->webauthnService->verifyAssertion(
                $authenticationCode,
                $optionsJson,
                $credentialRecord,
                $user->getId()->toRfc4122(),
            );

            $entity->setCredentialSource($this->webauthnService->serializeCredentialRecord($updatedRecord));
            $entity->setLastUsedAt(new DateTimeImmutable());
            $this->em->flush();

            $this->requestStack->getSession()->remove('_webauthn_assertion_options');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->formRenderer;
    }
}
