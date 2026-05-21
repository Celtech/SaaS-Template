<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521203320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, actor_id VARCHAR(36) DEFAULT NULL, actor_type VARCHAR(50) DEFAULT NULL, action VARCHAR(100) NOT NULL, subject_id VARCHAR(36) DEFAULT NULL, subject_type VARCHAR(50) DEFAULT NULL, old_value JSON DEFAULT NULL, new_value JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, impersonation_session_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_log_actor ON audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_log_subject ON audit_log (subject_id, subject_type)');
        $this->addSql('CREATE INDEX idx_audit_log_action ON audit_log (action)');
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON audit_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_log');
    }
}
