<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718084522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outgoing webhooks — endpoints and delivery log';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE webhook_deliveries (id UUID NOT NULL, event_type VARCHAR(100) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, attempts INT NOT NULL, next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_response_code INT DEFAULT NULL, last_response_body TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, endpoint_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3681F32D21AF7E36 ON webhook_deliveries (endpoint_id)');
        $this->addSql('CREATE INDEX idx_webhook_deliveries_due ON webhook_deliveries (status, next_attempt_at)');
        $this->addSql('CREATE TABLE webhook_endpoints (id UUID NOT NULL, url VARCHAR(2048) NOT NULL, secret_ciphertext TEXT NOT NULL, display_hint VARCHAR(4) NOT NULL, events JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E95677CC32C8A3DE ON webhook_endpoints (organization_id)');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_3681F32D21AF7E36 FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE webhook_endpoints ADD CONSTRAINT FK_E95677CC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT FK_3681F32D21AF7E36');
        $this->addSql('ALTER TABLE webhook_endpoints DROP CONSTRAINT FK_E95677CC32C8A3DE');
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('DROP TABLE webhook_endpoints');
    }
}
