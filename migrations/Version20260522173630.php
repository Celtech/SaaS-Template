<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522173630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace users.status enum with suspended_at and deleted_at timestamp columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_users_status');
        $this->addSql('ALTER TABLE users ADD suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users DROP status');
        $this->addSql('CREATE INDEX idx_users_deleted_at ON users (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_users_deleted_at');
        $this->addSql('ALTER TABLE users ADD status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE users DROP suspended_at');
        $this->addSql('ALTER TABLE users DROP deleted_at');
        $this->addSql('CREATE INDEX idx_users_status ON users (status)');
    }
}
