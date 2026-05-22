<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\Audit\AuditLogger;
use App\Service\Auth\AccountLockoutService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'auth_login';
    public const MSG_EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const MSG_ACCOUNT_INACTIVE = 'account_inactive';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AccountLockoutService $lockoutService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->getPayload()->getString('email');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $identifier) {
                $user = $this->lockoutService->getUserByEmail($identifier);

                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException('Invalid credentials.');
                }

                if (!$user->isActive()) {
                    throw new CustomUserMessageAuthenticationException(self::MSG_ACCOUNT_INACTIVE);
                }

                if ($user->isLocked()) {
                    throw new CustomUserMessageAuthenticationException('Your account has been temporarily locked due to too many failed login attempts.');
                }

                if (!$user->isEmailVerified()) {
                    throw new CustomUserMessageAuthenticationException(self::MSG_EMAIL_NOT_VERIFIED);
                }

                return $user;
            }),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $this->lockoutService->onSuccess($user);
            $this->auditLogger->logAuth('login.success', $user->getId()->toRfc4122());
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $email = $request->getPayload()->getString('email');

        if ($exception instanceof CustomUserMessageAuthenticationException
            && $exception->getMessageKey() === self::MSG_EMAIL_NOT_VERIFIED) {
            $this->auditLogger->logAuth('login.failure', null, 'user', [
                'email' => $email,
                'reason' => 'email_not_verified',
            ]);
        } elseif ($exception instanceof CustomUserMessageAuthenticationException
            && $exception->getMessageKey() === self::MSG_ACCOUNT_INACTIVE) {
            $this->auditLogger->logAuth('login.failure', null, 'user', [
                'email' => $email,
                'reason' => 'account_inactive',
            ]);
        } else {
            // Credential failures are counted and logged by AccountLockoutService
            $this->lockoutService->onFailure($email);
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
