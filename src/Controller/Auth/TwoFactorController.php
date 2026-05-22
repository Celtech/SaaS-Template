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

    private const PROVIDER_LABELS = [
        'totp' => 'Authenticator app',
        'email' => 'Email code',
    ];

    /** Defines display order on the selection page and the default preference. */
    private const PROVIDER_PRIORITY = ['totp', 'email'];

    #[Route('/2fa/select-provider', name: '2fa_select_provider')]
    public function selectProvider(): Response
    {
        $token = $this->tokenStorage->getToken();

        if (!$token instanceof TwoFactorTokenInterface) {
            return $this->redirectToRoute('2fa_login');
        }

        $providers = array_map(
            static fn (string $key) => [
                'key' => $key,
                'label' => self::PROVIDER_LABELS[$key] ?? ucfirst($key),
            ],
            $token->getTwoFactorProviders(),
        );

        usort($providers, static function (array $a, array $b): int {
            $posA = array_search($a['key'], self::PROVIDER_PRIORITY, true);
            $posB = array_search($b['key'], self::PROVIDER_PRIORITY, true);

            return ($posA === false ? \PHP_INT_MAX : $posA) <=> ($posB === false ? \PHP_INT_MAX : $posB);
        });

        return $this->render('auth/2fa_select_provider.html.twig', [
            'currentProvider' => $token->getCurrentTwoFactorProvider(),
            'providers' => $providers,
        ]);
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
            } elseif (\in_array('totp', $token->getTwoFactorProviders(), true)) {
                try {
                    $token->preferTwoFactorProvider('totp');
                } catch (UnknownTwoFactorProviderException) {
                    // ignore
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
