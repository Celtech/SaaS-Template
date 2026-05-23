<?php

declare(strict_types=1);

namespace App\Entity;

enum Permission: string
{
    // Admin namespace
    case AdminUsersView = 'admin.users.view';
    case AdminUsersEdit = 'admin.users.edit';
    case AdminUsersDelete = 'admin.users.delete';
    case AdminImpersonate = 'admin.impersonate';
    case AdminOrganizationsView = 'admin.organizations.view';
    case AdminOrganizationsEdit = 'admin.organizations.edit';
    case AdminBillingView = 'admin.billing.view';
    case AdminBillingManage = 'admin.billing.manage';
    case AdminPlansManage = 'admin.plans.manage';
    case AdminAuditLogsView = 'admin.audit_logs.view';
    case AdminWebhooksView = 'admin.webhooks.view';
    case AdminApiKeysView = 'admin.api_keys.view';
    case AdminSystemConfigure = 'admin.system.configure';
    case AdminAdminsManage = 'admin.admins.manage';

    // Org namespace
    case OrgMembersView = 'org.members.view';
    case OrgMembersInvite = 'org.members.invite';
    case OrgMembersManage = 'org.members.manage';
    case OrgMembersRemove = 'org.members.remove';
    case OrgApiKeysView = 'org.api_keys.view';
    case OrgApiKeysManage = 'org.api_keys.manage';
    case OrgWebhooksView = 'org.webhooks.view';
    case OrgWebhooksManage = 'org.webhooks.manage';
    case OrgBillingView = 'org.billing.view';
    case OrgBillingManage = 'org.billing.manage';
    case OrgSettingsView = 'org.settings.view';
    case OrgSettingsManage = 'org.settings.manage';
}
