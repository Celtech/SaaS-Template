<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends FunctionalTestCase
{
    #[Test]
    public function livenessEndpointReturns200(): void
    {
        $this->client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);
    }

    #[Test]
    public function livenessEndpointReturnsJson(): void
    {
        $this->client->request('GET', '/health');

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    #[Test]
    public function readinessEndpointReturns200WhenHealthy(): void
    {
        $this->client->request('GET', '/health/ready');

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('checks', $data);
        $this->assertSame('ok', $data['checks']['database']);
        $this->assertSame('ok', $data['checks']['cache']);
    }

    #[Test]
    public function readinessEndpointIncludesAllCheckKeys(): void
    {
        $this->client->request('GET', '/health/ready');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('database', $data['checks']);
        $this->assertArrayHasKey('cache', $data['checks']);
    }
}
