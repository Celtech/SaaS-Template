<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522042810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: users, user_sessions, email_verification_tokens, password_reset_tokens, data_erasure_requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_erasure_requests (id UUID NOT NULL, status VARCHAR(20) NOT NULL, reason TEXT DEFAULT NULL, error_context JSON DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_erasure_user ON data_erasure_requests (user_id)');
        $this->addSql('CREATE INDEX idx_erasure_status ON data_erasure_requests (status)');
        $this->addSql('CREATE TABLE email_verification_tokens (id UUID NOT NULL, token VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C81CA2AC5F37A13B ON email_verification_tokens (token)');
        $this->addSql('CREATE INDEX idx_email_verify_user ON email_verification_tokens (user_id)');
        $this->addSql('CREATE INDEX idx_email_verify_expires ON email_verification_tokens (expires_at)');
        $this->addSql('CREATE TABLE password_reset_tokens (id UUID NOT NULL, token VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A2165F37A13B ON password_reset_tokens (token)');
        $this->addSql('CREATE INDEX idx_pwd_reset_user ON password_reset_tokens (user_id)');
        $this->addSql('CREATE INDEX idx_pwd_reset_expires ON password_reset_tokens (expires_at)');
        $this->addSql('CREATE TABLE user_sessions (id UUID NOT NULL, session_token_hash VARCHAR(64) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_active_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_sessions_user ON user_sessions (user_id)');
        $this->addSql('CREATE INDEX idx_user_sessions_last_active ON user_sessions (last_active_at)');
        $this->addSql('CREATE INDEX idx_user_sessions_revoked ON user_sessions (revoked_at)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(100) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(255) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, failed_login_count SMALLINT NOT NULL, locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, totp_secret VARCHAR(255) DEFAULT NULL, totp_enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_users_status ON users (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');
        $this->addSql('ALTER TABLE data_erasure_requests ADD CONSTRAINT FK_A166FBFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_verification_tokens ADD CONSTRAINT FK_C81CA2ACA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_sessions ADD CONSTRAINT FK_7AED7913A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_erasure_requests DROP CONSTRAINT FK_A166FBFA76ED395');
        $this->addSql('ALTER TABLE email_verification_tokens DROP CONSTRAINT FK_C81CA2ACA76ED395');
        $this->addSql('ALTER TABLE password_reset_tokens DROP CONSTRAINT FK_3967A216A76ED395');
        $this->addSql('ALTER TABLE user_sessions DROP CONSTRAINT FK_7AED7913A76ED395');
        $this->addSql('DROP TABLE data_erasure_requests');
        $this->addSql('DROP TABLE email_verification_tokens');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE users');
    }
}
