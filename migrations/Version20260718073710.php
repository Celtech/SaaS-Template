<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718073710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth 2.0 — authorization codes for the Authorization Code + PKCE grant';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth_authorization_codes (id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, redirect_uri VARCHAR(500) NOT NULL, code_challenge VARCHAR(128) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_98A471C4E7530879 ON oauth_authorization_codes (code_hash)');
        $this->addSql('CREATE INDEX IDX_98A471C419EB6921 ON oauth_authorization_codes (client_id)');
        $this->addSql('CREATE INDEX IDX_98A471C4A76ED395 ON oauth_authorization_codes (user_id)');
        $this->addSql('CREATE INDEX IDX_98A471C432C8A3DE ON oauth_authorization_codes (organization_id)');
        $this->addSql('CREATE INDEX idx_oauth_authorization_codes_hash ON oauth_authorization_codes (code_hash)');
        $this->addSql('CREATE INDEX idx_oauth_authorization_codes_expiry ON oauth_authorization_codes (expires_at)');
        $this->addSql('ALTER TABLE oauth_authorization_codes ADD CONSTRAINT FK_98A471C419EB6921 FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_authorization_codes ADD CONSTRAINT FK_98A471C4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_authorization_codes ADD CONSTRAINT FK_98A471C432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_authorization_codes DROP CONSTRAINT FK_98A471C419EB6921');
        $this->addSql('ALTER TABLE oauth_authorization_codes DROP CONSTRAINT FK_98A471C4A76ED395');
        $this->addSql('ALTER TABLE oauth_authorization_codes DROP CONSTRAINT FK_98A471C432C8A3DE');
        $this->addSql('DROP TABLE oauth_authorization_codes');
    }
}
