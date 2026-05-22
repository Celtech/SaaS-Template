<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Exception\UnknownTwoFactorProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/2fa/login', name: '2fa_login')]
    public function form(Request $request): Response
    {
        $token = $this->tokenStorage->getToken();

        if ($token instanceof TwoFactorTokenInterface) {
            $prefer = $request->query->getString('preferProvider');
            if ($prefer !== '') {
                try {
                    $token->preferTwoFactorProvider($prefer);
                } catch (UnknownTwoFactorProviderException) {
                    // ignore invalid provider names
                }
            }
        }

        $session = $request->getSession();
        $authError = $session->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        if ($authError instanceof AuthenticationException) {
            $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        }

        $currentProvider = $token instanceof TwoFactorTokenInterface ? $token->getCurrentTwoFactorProvider() : null;
        $availableProviders = $token instanceof TwoFactorTokenInterface ? $token->getTwoFactorProviders() : [];

        $template = $currentProvider === 'email' ? 'auth/2fa_email.html.twig' : 'auth/2fa.html.twig';

        return $this->render($template, [
            'authenticationError' => $authError instanceof AuthenticationException ? $authError->getMessageKey() : null,
            'twoFactorProvider' => $currentProvider,
            'availableTwoFactorProviders' => $availableProviders,
        ]);
    }
}
