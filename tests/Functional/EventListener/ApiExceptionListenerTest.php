<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventListener;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ApiExceptionListenerTest extends FunctionalTestCase
{
    #[Test]
    public function itRendersA404AsProblemDetailsForAnUnknownApiRoute(): void
    {
        $this->client->request('GET', '/api/v1/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(404, $data['status']);
        $this->assertSame('Not Found', $data['title']);
    }

    #[Test]
    public function itDoesNotAffectNonApiRoutes(): void
    {
        $this->client->request('GET', '/this-route-does-not-exist');

        $this->assertResponseStatusCodeSame(404);
        $this->assertNotSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }
}
