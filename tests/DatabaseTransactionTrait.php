<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;

trait DatabaseTransactionTrait
{
    private ?Connection $connection = null;

    protected function beginTransaction(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function rollbackTransaction(): void
    {
        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection = null;
    }
}
