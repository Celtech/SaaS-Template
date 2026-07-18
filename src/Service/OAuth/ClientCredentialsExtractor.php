<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Symfony\Component\HttpFoundation\Request;

/** Extracts client_id/client_secret from a token-endpoint request per RFC 6749 §2.3.1. */
final class ClientCredentialsExtractor
{
    /** @return array{string|null, string|null} */
    public function extract(Request $request): array
    {
        // Prefer HTTP Basic (RFC 6749 §2.3.1).
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), strict: true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [$id ?: null, $secret ?: null];
            }
        }

        // Fall back to POST body parameters.
        $id = $request->request->getString('client_id') ?: null;
        $secret = $request->request->getString('client_secret') ?: null;

        return [$id, $secret];
    }
}
