<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TwoFactorController extends AbstractController
{
    #[Route('/2fa/login', name: '2fa_login')]
    public function form(): Response
    {
        return $this->render('auth/2fa.html.twig');
    }
}
