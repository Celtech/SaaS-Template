<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class TwoFactorController extends AbstractController
{
    #[Route('/2fa/login', name: '2fa_login')]
    public function form(Request $request): Response
    {
        $session = $request->getSession();
        $authError = $session->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        if ($authError instanceof AuthenticationException) {
            $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        }

        return $this->render('auth/2fa.html.twig', [
            'authenticationError' => $authError instanceof AuthenticationException ? $authError->getMessageKey() : null,
        ]);
    }
}
