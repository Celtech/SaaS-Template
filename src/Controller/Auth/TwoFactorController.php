<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Security\WebauthnTwoFactorProvider;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\Generator\CodeGeneratorInterface;
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
    private const PROVIDER_LABELS = [
        'totp' => 'Authenticator app',
        'email' => 'Email code',
        'webauthn' => 'Security key',
    ];

    /** Defines display order on the selection page and the default preference. */
    private const PROVIDER_PRIORITY = ['webauthn', 'totp', 'email'];

    private const SESSION_RESEND_KEY = '_2fa_email_last_sent';
    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CodeGeneratorInterface $codeGenerator,
        private readonly WebauthnTwoFactorProvider $webauthnProvider,
    ) {
    }

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
            } else {
                foreach (self::PROVIDER_PRIORITY as $preferred) {
                    if (\in_array($preferred, $token->getTwoFactorProviders(), true)) {
                        try {
                            $token->preferTwoFactorProvider($preferred);
                        } catch (UnknownTwoFactorProviderException) {
                            // ignore
                        }
                        break;
                    }
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

        $resendCooldown = 0;
        if ($currentProvider === 'email') {
            $lastSent = $session->get(self::SESSION_RESEND_KEY);
            if ($lastSent === null) {
                $session->set(self::SESSION_RESEND_KEY, time());
                $lastSent = time();
            }
            $resendCooldown = max(0, self::RESEND_COOLDOWN - (time() - (int) $lastSent));
        }

        $tokenUser = $token instanceof TwoFactorTokenInterface ? $token->getUser() : null;
        $hasBackupCodes = $tokenUser instanceof User && $tokenUser->hasBackupCodes();

        if ($currentProvider === 'webauthn' && !\is_string($session->get('_webauthn_assertion_options')) && $tokenUser !== null && $token instanceof TwoFactorTokenInterface) {
            $this->webauthnProvider->prepareAuthentication($tokenUser);
            // Mark as prepared so scheb's onKernelResponse listener doesn't overwrite
            // the challenge we just stored — it fires after the controller renders the
            // template, so without this the session would contain a different challenge
            // than what was embedded in the page HTML.
            $token->setTwoFactorProviderPrepared('webauthn');
        }

        $template = match ($currentProvider) {
            'email' => 'auth/2fa_email.html.twig',
            'webauthn' => 'auth/2fa_webauthn.html.twig',
            default => 'auth/2fa.html.twig',
        };

        return $this->render($template, [
            'authenticationError' => $authError instanceof AuthenticationException ? $authError->getMessageKey() : null,
            'twoFactorProvider' => $currentProvider,
            'availableTwoFactorProviders' => $availableProviders,
            'resendCooldown' => $resendCooldown,
            'hasBackupCodes' => $hasBackupCodes,
            'webauthnOptionsJson' => $session->get('_webauthn_assertion_options', '{}'),
        ]);
    }

    #[Route('/2fa/backup-code', name: '2fa_backup_code')]
    public function backupCode(Request $request): Response
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TwoFactorTokenInterface) {
            return $this->redirectToRoute('2fa_login');
        }

        $session = $request->getSession();
        $authError = $session->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        if ($authError instanceof AuthenticationException) {
            $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        }

        return $this->render('auth/2fa_backup.html.twig', [
            'authenticationError' => $authError instanceof AuthenticationException ? $authError->getMessageKey() : null,
        ]);
    }

    #[Route('/2fa/resend-code', name: '2fa_resend_code', methods: ['POST'])]
    public function resendCode(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('2fa_resend', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TwoFactorTokenInterface) {
            return $this->redirectToRoute('2fa_login');
        }

        $session = $request->getSession();
        $lastSent = (int) ($session->get(self::SESSION_RESEND_KEY) ?? 0);

        if (time() - $lastSent < self::RESEND_COOLDOWN) {
            return $this->redirectToRoute('2fa_login', ['preferProvider' => 'email']);
        }

        $user = $token->getUser();
        if ($user instanceof EmailTwoFactorInterface) {
            $this->codeGenerator->generateAndSend($user);
            $session->set(self::SESSION_RESEND_KEY, time());
        }

        return $this->redirectToRoute('2fa_login', ['preferProvider' => 'email']);
    }
}
