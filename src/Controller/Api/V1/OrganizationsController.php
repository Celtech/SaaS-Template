<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Api\ApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every token — delegated or Client Credentials — carries a single organization
 * context (see TokenService::issueTokenPair). This returns that organization;
 * the plural route name matches the ROADMAP spec and leaves room for multi-org
 * membership without a breaking route change.
 */
#[Route('/api/v1/organizations')]
final class OrganizationsController extends ApiController
{
    #[Route('', name: 'api_v1_organizations', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->denyAccessUnlessScope($request, 'org:read');

        $organization = $this->oauthToken($request)->getOrganization();

        if ($organization === null) {
            return $this->apiData([]);
        }

        return $this->apiData([
            [
                'id' => $organization->getId()->toRfc4122(),
                'name' => $organization->getName(),
            ],
        ]);
    }
}
