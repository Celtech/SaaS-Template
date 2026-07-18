<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718081055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth 2.0 — device codes for the Device Authorization grant';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth_device_codes (id UUID NOT NULL, device_code_hash VARCHAR(64) NOT NULL, user_code_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, interval INT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, denied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id UUID NOT NULL, user_id UUID DEFAULT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_62707AE6DCB31CE8 ON oauth_device_codes (device_code_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_62707AE6E7B3859D ON oauth_device_codes (user_code_hash)');
        $this->addSql('CREATE INDEX IDX_62707AE619EB6921 ON oauth_device_codes (client_id)');
        $this->addSql('CREATE INDEX IDX_62707AE6A76ED395 ON oauth_device_codes (user_id)');
        $this->addSql('CREATE INDEX IDX_62707AE632C8A3DE ON oauth_device_codes (organization_id)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_device_hash ON oauth_device_codes (device_code_hash)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_user_hash ON oauth_device_codes (user_code_hash)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_expiry ON oauth_device_codes (expires_at)');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT FK_62707AE619EB6921 FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT FK_62707AE6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT FK_62707AE632C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_device_codes DROP CONSTRAINT FK_62707AE619EB6921');
        $this->addSql('ALTER TABLE oauth_device_codes DROP CONSTRAINT FK_62707AE6A76ED395');
        $this->addSql('ALTER TABLE oauth_device_codes DROP CONSTRAINT FK_62707AE632C8A3DE');
        $this->addSql('DROP TABLE oauth_device_codes');
    }
}
