<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Auth;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RateLimitingTest extends FunctionalTestCase
{
    #[Test]
    public function registrationIsRateLimitedPerIp(): void
    {
        // AUTH_RATE_LIMIT_REGISTER_PER_HOUR defaults to 5 (see .env).
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/auth/register', [
                'registration_form' => [
                    'name' => 'Rate Limit Test',
                    'email' => \sprintf('rate-limit-register-%d@example.com', $i),
                    'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                    'agreeTerms' => '1',
                    '_token' => $this->csrfToken('registration_form'),
                ],
            ]);
            $this->assertNotSame(429, $this->client->getResponse()->getStatusCode(), "Attempt $i should not be rate limited yet");
        }

        $this->client->request('POST', '/auth/register', [
            'registration_form' => [
                'name' => 'Rate Limit Test',
                'email' => 'rate-limit-register-overflow@example.com',
                'plainPassword' => ['first' => 'Password123!', 'second' => 'Password123!'],
                'agreeTerms' => '1',
                '_token' => $this->csrfToken('registration_form'),
            ],
        ]);

        $this->assertResponseStatusCodeSame(429);
    }

    #[Test]
    public function forgotPasswordIsRateLimitedPerIp(): void
    {
        // AUTH_RATE_LIMIT_FORGOT_PASSWORD_PER_HOUR defaults to 5 (see .env).
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/auth/forgot-password', [
                'forgot_password_form' => [
                    'email' => 'nobody@example.com',
                    '_token' => $this->csrfToken('forgot_password_form'),
                ],
            ]);
            $this->assertNotSame(429, $this->client->getResponse()->getStatusCode(), "Attempt $i should not be rate limited yet");
        }

        $this->client->request('POST', '/auth/forgot-password', [
            'forgot_password_form' => [
                'email' => 'nobody@example.com',
                '_token' => $this->csrfToken('forgot_password_form'),
            ],
        ]);

        $this->assertResponseStatusCodeSame(429);
    }

    private function csrfToken(string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }
}
