<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527155311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth 2.0 — clients, access tokens, and refresh tokens';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth_access_tokens (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id UUID NOT NULL, user_id UUID DEFAULT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CA42527CB3BC57DA ON oauth_access_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_CA42527C19EB6921 ON oauth_access_tokens (client_id)');
        $this->addSql('CREATE INDEX IDX_CA42527CA76ED395 ON oauth_access_tokens (user_id)');
        $this->addSql('CREATE INDEX IDX_CA42527C32C8A3DE ON oauth_access_tokens (organization_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_hash ON oauth_access_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_expiry ON oauth_access_tokens (expires_at)');
        $this->addSql('CREATE TABLE oauth_clients (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(500) DEFAULT NULL, client_id VARCHAR(80) NOT NULL, client_secret_hash VARCHAR(64) DEFAULT NULL, redirect_uris JSON NOT NULL, allowed_grants JSON NOT NULL, allowed_scopes JSON NOT NULL, is_confidential BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_13CE810119EB6921 ON oauth_clients (client_id)');
        $this->addSql('CREATE INDEX IDX_13CE810132C8A3DE ON oauth_clients (organization_id)');
        $this->addSql('CREATE TABLE oauth_refresh_tokens (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id UUID NOT NULL, user_id UUID DEFAULT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5AB687B3BC57DA ON oauth_refresh_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_5AB68719EB6921 ON oauth_refresh_tokens (client_id)');
        $this->addSql('CREATE INDEX IDX_5AB687A76ED395 ON oauth_refresh_tokens (user_id)');
        $this->addSql('CREATE INDEX IDX_5AB68732C8A3DE ON oauth_refresh_tokens (organization_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_hash ON oauth_refresh_tokens (token_hash)');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT FK_CA42527C19EB6921 FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT FK_CA42527CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT FK_CA42527C32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_clients ADD CONSTRAINT FK_13CE810132C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD CONSTRAINT FK_5AB68719EB6921 FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD CONSTRAINT FK_5AB687A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD CONSTRAINT FK_5AB68732C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE billing_settings ALTER require_credit_card DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT FK_CA42527C19EB6921');
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT FK_CA42527CA76ED395');
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT FK_CA42527C32C8A3DE');
        $this->addSql('ALTER TABLE oauth_clients DROP CONSTRAINT FK_13CE810132C8A3DE');
        $this->addSql('ALTER TABLE oauth_refresh_tokens DROP CONSTRAINT FK_5AB68719EB6921');
        $this->addSql('ALTER TABLE oauth_refresh_tokens DROP CONSTRAINT FK_5AB687A76ED395');
        $this->addSql('ALTER TABLE oauth_refresh_tokens DROP CONSTRAINT FK_5AB68732C8A3DE');
        $this->addSql('DROP TABLE oauth_access_tokens');
        $this->addSql('DROP TABLE oauth_clients');
        $this->addSql('DROP TABLE oauth_refresh_tokens');
        $this->addSql('ALTER TABLE billing_settings ALTER require_credit_card SET DEFAULT false');
    }
}
