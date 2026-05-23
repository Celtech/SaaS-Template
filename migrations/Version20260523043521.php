<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523043521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organizations, org_invitations, roles, role_permissions, user_roles; add organization_id to users';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE org_invitations (id UUID NOT NULL, email VARCHAR(255) NOT NULL, token VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, invited_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BFAB4A485F37A13B ON org_invitations (token)');
        $this->addSql('CREATE INDEX IDX_BFAB4A4832C8A3DE ON org_invitations (organization_id)');
        $this->addSql('CREATE INDEX IDX_BFAB4A48A7B4A7E3 ON org_invitations (invited_by_id)');
        $this->addSql('CREATE INDEX idx_org_invitations_email ON org_invitations (email)');
        $this->addSql('CREATE TABLE organizations (id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_427C1C7F7E3C61F9 ON organizations (owner_id)');
        $this->addSql('CREATE TABLE role_permissions (permission_key VARCHAR(100) NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (role_id, permission_key))');
        $this->addSql('CREATE INDEX IDX_1FBA94E6D60322AC ON role_permissions (role_id)');
        $this->addSql('CREATE TABLE roles (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, description VARCHAR(500) DEFAULT NULL, context VARCHAR(20) NOT NULL, is_system BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B63E2EC7989D9B62 ON roles (slug)');
        $this->addSql('CREATE TABLE user_roles (id UUID NOT NULL, context_id UUID DEFAULT NULL, assigned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_54FCD59FA76ED395 ON user_roles (user_id)');
        $this->addSql('CREATE INDEX IDX_54FCD59FD60322AC ON user_roles (role_id)');
        $this->addSql('CREATE INDEX idx_user_roles_user_context ON user_roles (user_id, context_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_role_context ON user_roles (user_id, role_id, context_id)');
        $this->addSql('ALTER TABLE org_invitations ADD CONSTRAINT FK_BFAB4A4832C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE org_invitations ADD CONSTRAINT FK_BFAB4A48A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organizations ADD CONSTRAINT FK_427C1C7F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE users ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E932C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1483A5E932C8A3DE ON users (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_invitations DROP CONSTRAINT FK_BFAB4A4832C8A3DE');
        $this->addSql('ALTER TABLE org_invitations DROP CONSTRAINT FK_BFAB4A48A7B4A7E3');
        $this->addSql('ALTER TABLE organizations DROP CONSTRAINT FK_427C1C7F7E3C61F9');
        $this->addSql('ALTER TABLE role_permissions DROP CONSTRAINT FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE user_roles DROP CONSTRAINT FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP CONSTRAINT FK_54FCD59FD60322AC');
        $this->addSql('DROP TABLE org_invitations');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E932C8A3DE');
        $this->addSql('DROP INDEX IDX_1483A5E932C8A3DE');
        $this->addSql('ALTER TABLE users DROP organization_id');
    }
}
