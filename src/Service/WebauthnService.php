<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebauthnService
{
    private readonly SerializerInterface $serializer;
    private readonly AuthenticatorAttestationResponseValidator $attestationValidator;
    private readonly AuthenticatorAssertionResponseValidator $assertionValidator;
    private readonly string $rpId;

    public function __construct(
        string $appUrl,
        private readonly string $appName,
    ) {
        $this->rpId = parse_url($appUrl, \PHP_URL_HOST) ?: 'localhost';

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$appUrl]);

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $factory->creationCeremony()
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $factory->requestCeremony()
        );

        $attManager = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer = new WebauthnSerializerFactory($attManager)->create();
    }

    public function getRpId(): string
    {
        return $this->rpId;
    }

    /** @param string[] $existingCredentialIds base64url-encoded credential IDs to exclude */
    public function createRegistrationOptions(
        string $userId,
        string $userEmail,
        array $existingCredentialIds = [],
    ): PublicKeyCredentialCreationOptions {
        $excludeCredentials = array_map(
            fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', $this->decodeBase64Url($id)),
            $existingCredentialIds,
        );

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: $this->appName, id: $this->rpId),
            user: PublicKeyCredentialUserEntity::create(
                name: $userEmail,
                id: $userId,
                displayName: $userEmail,
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(-7),   // ES256
                PublicKeyCredentialParameters::createPk(-257), // RS256
            ],
            excludeCredentials: $excludeCredentials,
        );
    }

    public function verifyRegistration(string $responseJson, string $optionsJson): CredentialRecord
    {
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $publicKeyCredential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');

        \assert($publicKeyCredential->response instanceof AuthenticatorAttestationResponse);

        return $this->attestationValidator->check(
            $publicKeyCredential->response,
            $options,
            $this->rpId,
        );
    }

    /** @param string[] $allowedCredentialIds base64url-encoded credential IDs */
    public function createAssertionOptions(array $allowedCredentialIds = []): PublicKeyCredentialRequestOptions
    {
        $allowedCredentials = array_map(
            fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', $this->decodeBase64Url($id)),
            $allowedCredentialIds,
        );

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->rpId,
            allowCredentials: $allowedCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );
    }

    public function verifyAssertion(
        string $responseJson,
        string $optionsJson,
        CredentialRecord $credentialRecord,
        string $userHandle,
    ): CredentialRecord {
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        $publicKeyCredential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');

        \assert($publicKeyCredential->response instanceof AuthenticatorAssertionResponse);

        return $this->assertionValidator->check(
            $credentialRecord,
            $publicKeyCredential->response,
            $options,
            $this->rpId,
            $userHandle,
        );
    }

    public function serializeOptions(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options,
    ): string {
        return $this->serializer->serialize($options, 'json');
    }

    /** @return array<string, mixed> */
    public function serializeCredentialRecord(CredentialRecord $record): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->serializer->serialize($record, 'json'), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public function deserializeCredentialRecord(array $data): CredentialRecord
    {
        return $this->serializer->deserialize(
            json_encode($data, \JSON_THROW_ON_ERROR),
            CredentialRecord::class,
            'json',
        );
    }

    /** Convert binary credential ID to base64url for storage and browser interop */
    public function encodeBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $base64url): string
    {
        return (string) base64_decode(str_pad(strtr($base64url, '-_', '+/'), \strlen($base64url) + (4 - \strlen($base64url) % 4) % 4, '='), true);
    }
}
