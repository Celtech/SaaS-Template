<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523171340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 4: billing core — plans, entitlements, subscriptions, billing_settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE billing_settings (id INT NOT NULL, trial_enabled BOOLEAN NOT NULL, trial_days INT NOT NULL, free_tier_enabled BOOLEAN NOT NULL, trial_expiry_behavior VARCHAR(255) NOT NULL, grace_period_days INT NOT NULL, free_tier_plan_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FD99C939E787577 ON billing_settings (free_tier_plan_id)');
        $this->addSql('CREATE TABLE entitlements (id UUID NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_entitlement_slug ON entitlements (slug)');
        $this->addSql('CREATE TABLE plan_entitlements (id UUID NOT NULL, value VARCHAR(50) NOT NULL, plan_id UUID NOT NULL, entitlement_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8D099ADDE899029B ON plan_entitlements (plan_id)');
        $this->addSql('CREATE INDEX IDX_8D099ADD15FCF4DF ON plan_entitlements (entitlement_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_entitlement ON plan_entitlements (plan_id, entitlement_id)');
        $this->addSql('CREATE TABLE plans (id UUID NOT NULL, slug VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, stripe_product_id VARCHAR(64) DEFAULT NULL, stripe_price_id_monthly VARCHAR(64) DEFAULT NULL, stripe_price_id_annual VARCHAR(64) DEFAULT NULL, monthly_price_cents INT NOT NULL, annual_price_cents INT NOT NULL, is_active BOOLEAN NOT NULL, is_free BOOLEAN NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_slug ON plans (slug)');
        $this->addSql('CREATE TABLE subscriptions (id UUID NOT NULL, status VARCHAR(255) NOT NULL, stripe_subscription_id VARCHAR(64) DEFAULT NULL, stripe_customer_id VARCHAR(64) DEFAULT NULL, stripe_price_id VARCHAR(64) DEFAULT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancel_at_period_end BOOLEAN NOT NULL, canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A0132C8A3DE ON subscriptions (organization_id)');
        $this->addSql('CREATE INDEX IDX_4778A01E899029B ON subscriptions (plan_id)');
        $this->addSql('CREATE INDEX idx_subscription_stripe_id ON subscriptions (stripe_subscription_id)');
        $this->addSql('CREATE INDEX idx_subscription_stripe_customer ON subscriptions (stripe_customer_id)');
        $this->addSql('ALTER TABLE billing_settings ADD CONSTRAINT FK_FD99C939E787577 FOREIGN KEY (free_tier_plan_id) REFERENCES plans (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_entitlements ADD CONSTRAINT FK_8D099ADDE899029B FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_entitlements ADD CONSTRAINT FK_8D099ADD15FCF4DF FOREIGN KEY (entitlement_id) REFERENCES entitlements (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A0132C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A01E899029B FOREIGN KEY (plan_id) REFERENCES plans (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_settings DROP CONSTRAINT FK_FD99C939E787577');
        $this->addSql('ALTER TABLE plan_entitlements DROP CONSTRAINT FK_8D099ADDE899029B');
        $this->addSql('ALTER TABLE plan_entitlements DROP CONSTRAINT FK_8D099ADD15FCF4DF');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A0132C8A3DE');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A01E899029B');
        $this->addSql('DROP TABLE billing_settings');
        $this->addSql('DROP TABLE entitlements');
        $this->addSql('DROP TABLE plan_entitlements');
        $this->addSql('DROP TABLE plans');
        $this->addSql('DROP TABLE subscriptions');
    }
}
