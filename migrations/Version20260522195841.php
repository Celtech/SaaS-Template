<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522195841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE webauthn_credentials (id UUID NOT NULL, name VARCHAR(100) NOT NULL, credential_id VARCHAR(512) NOT NULL, credential_source JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DFEA84902558A7A5 ON webauthn_credentials (credential_id)');
        $this->addSql('CREATE INDEX IDX_DFEA8490A76ED395 ON webauthn_credentials (user_id)');
        $this->addSql('CREATE INDEX idx_webauthn_credential_id ON webauthn_credentials (credential_id)');
        $this->addSql('ALTER TABLE webauthn_credentials ADD CONSTRAINT FK_DFEA8490A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE webauthn_credentials DROP CONSTRAINT FK_DFEA8490A76ED395');
        $this->addSql('DROP TABLE webauthn_credentials');
    }
}
