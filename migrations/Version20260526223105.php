<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526223105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_settings ADD require_credit_card BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE billing_settings ADD default_trial_plan_slug VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE plans ALTER is_custom_quote DROP DEFAULT');
        $this->addSql('ALTER TABLE plans ALTER is_public DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_settings DROP require_credit_card');
        $this->addSql('ALTER TABLE billing_settings DROP default_trial_plan_slug');
        $this->addSql('ALTER TABLE plans ALTER is_custom_quote SET DEFAULT false');
        $this->addSql('ALTER TABLE plans ALTER is_public SET DEFAULT true');
    }
}
