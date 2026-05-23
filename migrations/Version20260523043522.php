<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523043522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default admin and org roles with permissions';
    }

    public function up(Schema $schema): void
    {
        // Admin roles
        $this->addSql("INSERT INTO roles (id, name, slug, description, context, is_system, created_at) VALUES
            (gen_random_uuid(), 'Support', 'admin-support', 'Read-only access to users, organizations, and audit logs', 'admin', true, NOW()),
            (gen_random_uuid(), 'Admin', 'admin-admin', 'Full admin access excluding system configuration', 'admin', true, NOW()),
            (gen_random_uuid(), 'Super Admin', 'admin-super-admin', 'Unrestricted system access', 'admin', true, NOW())
        ");

        // Org roles
        $this->addSql("INSERT INTO roles (id, name, slug, description, context, is_system, created_at) VALUES
            (gen_random_uuid(), 'Member', 'org-member', 'Basic read access within an organization', 'org', true, NOW()),
            (gen_random_uuid(), 'Admin', 'org-admin', 'Manage members, API keys, webhooks, and settings', 'org', true, NOW()),
            (gen_random_uuid(), 'Owner', 'org-owner', 'Full organization access including billing', 'org', true, NOW())
        ");

        // Support permissions
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('admin.users.view'), ('admin.organizations.view'), ('admin.audit_logs.view')
            ) AS p(key)
            WHERE slug = 'admin-support'
        ");

        // Admin permissions (Support + more)
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('admin.users.view'), ('admin.users.edit'), ('admin.users.delete'),
                ('admin.impersonate'),
                ('admin.organizations.view'), ('admin.organizations.edit'),
                ('admin.billing.view'),
                ('admin.audit_logs.view'),
                ('admin.webhooks.view'), ('admin.api_keys.view')
            ) AS p(key)
            WHERE slug = 'admin-admin'
        ");

        // Super Admin permissions (all)
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('admin.users.view'), ('admin.users.edit'), ('admin.users.delete'),
                ('admin.impersonate'),
                ('admin.organizations.view'), ('admin.organizations.edit'),
                ('admin.billing.view'), ('admin.billing.manage'),
                ('admin.plans.manage'),
                ('admin.audit_logs.view'),
                ('admin.webhooks.view'), ('admin.api_keys.view'),
                ('admin.system.configure'), ('admin.admins.manage')
            ) AS p(key)
            WHERE slug = 'admin-super-admin'
        ");

        // Org Member permissions
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('org.members.view'),
                ('org.api_keys.view'),
                ('org.webhooks.view'),
                ('org.billing.view'),
                ('org.settings.view')
            ) AS p(key)
            WHERE slug = 'org-member'
        ");

        // Org Admin permissions (Member + manage)
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('org.members.view'), ('org.members.invite'), ('org.members.manage'), ('org.members.remove'),
                ('org.api_keys.view'), ('org.api_keys.manage'),
                ('org.webhooks.view'), ('org.webhooks.manage'),
                ('org.billing.view'),
                ('org.settings.view'), ('org.settings.manage')
            ) AS p(key)
            WHERE slug = 'org-admin'
        ");

        // Org Owner permissions (all org)
        $this->addSql("INSERT INTO role_permissions (role_id, permission_key)
            SELECT id, p.key FROM roles, (VALUES
                ('org.members.view'), ('org.members.invite'), ('org.members.manage'), ('org.members.remove'),
                ('org.api_keys.view'), ('org.api_keys.manage'),
                ('org.webhooks.view'), ('org.webhooks.manage'),
                ('org.billing.view'), ('org.billing.manage'),
                ('org.settings.view'), ('org.settings.manage')
            ) AS p(key)
            WHERE slug = 'org-owner'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM roles WHERE slug IN ('admin-support', 'admin-admin', 'admin-super-admin', 'org-member', 'org-admin', 'org-owner') AND is_system = true");
    }
}
