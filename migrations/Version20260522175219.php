<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522175219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email 2FA columns to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD email_auth_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE users ADD email_auth_code VARCHAR(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP email_auth_enabled');
        $this->addSql('ALTER TABLE users DROP email_auth_code');
    }
}
