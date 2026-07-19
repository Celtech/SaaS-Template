<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use App\Entity\User;
use App\Security\OAuth\OAuthClientPrincipal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Who am I" endpoint — works for both delegated (user) and Client Credentials
 * (M2M) tokens, since both are valid OAuth principals. Field visibility on the
 * user variant is gated by the granted scopes, same as an OIDC /userinfo endpoint.
 */
#[Route('/api/v1/me')]
final class MeController extends ApiController
{
    #[Route('', name: 'api_v1_me', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = $this->getUser();

        if ($principal instanceof OAuthClientPrincipal) {
            $client = $principal->getClient();

            return $this->apiData([
                'type' => 'client',
                'id' => $client->getClientId(),
                'name' => $client->getName(),
                'organization_id' => $client->getOrganization()?->getId()->toRfc4122(),
            ]);
        }

        /** @var User $principal */
        $scopes = $this->oauthScopes($request);
        $data = [
            'type' => 'user',
            'id' => $principal->getId()->toRfc4122(),
            'organization_id' => $principal->getOrganization()?->getId()->toRfc4122(),
        ];

        if (\in_array('profile', $scopes, true)) {
            $data['name'] = $principal->getName();
        }

        if (\in_array('email', $scopes, true)) {
            $data['email'] = $principal->getEmail();
        }

        return $this->apiData($data);
    }
}
