<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527004201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused free_tier_enabled and free_tier_plan_id from billing_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_settings DROP CONSTRAINT IF EXISTS fk_fd99c939e787577');
        $this->addSql('DROP INDEX IF EXISTS idx_fd99c939e787577');
        $this->addSql('ALTER TABLE billing_settings DROP free_tier_enabled');
        $this->addSql('ALTER TABLE billing_settings DROP free_tier_plan_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_settings ADD free_tier_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE billing_settings ADD free_tier_plan_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_fd99c939e787577 ON billing_settings (free_tier_plan_id)');
        $this->addSql('ALTER TABLE billing_settings ADD CONSTRAINT fk_fd99c939e787577 FOREIGN KEY (free_tier_plan_id) REFERENCES plans (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
