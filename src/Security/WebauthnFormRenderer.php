<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class WebauthnFormRenderer implements TwoFactorFormRendererInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function renderForm(Request $request, array $templateVars): Response
    {
        $optionsJson = $request->getSession()->get('_webauthn_assertion_options', '{}');

        return new Response(
            $this->twig->render('auth/2fa_webauthn.html.twig', array_merge($templateVars, [
                'webauthnOptionsJson' => $optionsJson,
            ]))
        );
    }
}
