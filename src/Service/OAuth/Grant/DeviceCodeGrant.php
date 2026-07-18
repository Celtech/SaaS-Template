<?php

declare(strict_types=1);

namespace App\Service\OAuth\Grant;

use App\Service\OAuth\ClientCredentialsExtractor;
use App\Service\OAuth\ClientService;
use App\Service\OAuth\DeviceCodeService;
use App\Service\OAuth\TokenService;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** RFC 8628 §3.4 — the polling/token-exchange half of the Device Authorization grant. */
final class DeviceCodeGrant
{
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    public function __construct(
        private readonly ClientService $clientService,
        private readonly DeviceCodeService $deviceCodeService,
        private readonly TokenService $tokenService,
        private readonly ClientCredentialsExtractor $credentialsExtractor,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $plainDeviceCode = $request->request->getString('device_code');

        if ($plainDeviceCode === '') {
            return $this->error('invalid_request', 'device_code is required.');
        }

        [$clientId, $clientSecret] = $this->credentialsExtractor->extract($request);

        if ($clientId === null) {
            return $this->error('invalid_client', 'client_id is required.', Response::HTTP_UNAUTHORIZED);
        }

        $client = $this->clientService->findByClientId($clientId);

        if ($client === null) {
            return $this->error('invalid_client', 'Invalid client.', Response::HTTP_UNAUTHORIZED);
        }

        if ($client->isConfidential()) {
            if ($clientSecret === null || $this->clientService->validateClientCredentials($clientId, $clientSecret) === null) {
                return $this->error('invalid_client', 'Invalid client credentials.', Response::HTTP_UNAUTHORIZED);
            }
        }

        if (!$client->supportsGrant(self::GRANT_TYPE)) {
            return $this->error('unauthorized_client', 'This client is not authorized for the device_code grant.');
        }

        $deviceCode = $this->deviceCodeService->findByDeviceCode($plainDeviceCode);

        if ($deviceCode === null || !$deviceCode->getClient()->getId()->equals($client->getId())) {
            return $this->error('invalid_grant', 'device_code is invalid or unknown.');
        }

        if ($deviceCode->isExpired()) {
            return $this->error('expired_token', 'The device_code has expired.');
        }

        if ($deviceCode->isDenied()) {
            return $this->error('access_denied', 'The user denied the authorization request.');
        }

        if (!$deviceCode->isApproved()) {
            $lastPolledAt = $deviceCode->getLastPolledAt();
            $tooSoon = $lastPolledAt !== null
                && new DateTimeImmutable()->getTimestamp() - $lastPolledAt->getTimestamp() < $deviceCode->getInterval();

            $deviceCode->recordPoll();
            $this->deviceCodeService->persist($deviceCode);

            return $tooSoon
                ? $this->error('slow_down', 'Polling too frequently; increase your interval.')
                : $this->error('authorization_pending', 'The user has not yet completed authorization.');
        }

        if ($deviceCode->isConsumed()) {
            return $this->error('invalid_grant', 'device_code has already been redeemed.');
        }

        $deviceCode->markConsumed();
        $this->deviceCodeService->persist($deviceCode);

        [, , $plainAccess, $plainRefresh] = $this->tokenService->issueTokenPair(
            client: $client,
            user: $deviceCode->getUser(),
            organization: $deviceCode->getOrganization(),
            scopes: $deviceCode->getScopes(),
            includeRefreshToken: $client->supportsGrant('refresh_token'),
        );

        $response = [
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => TokenService::accessTokenTtl(),
            'scope' => implode(' ', $deviceCode->getScopes()),
        ];

        if ($plainRefresh !== null) {
            $response['refresh_token'] = $plainRefresh;
        }

        return new JsonResponse($response);
    }

    private function error(string $code, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code, 'error_description' => $description],
            $status,
        );
    }
}
