<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719074127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification system — notifications and per-user channel preferences';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification_preferences (id UUID NOT NULL, notification_type VARCHAR(100) NOT NULL, channel VARCHAR(50) NOT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3CAA95B4A76ED395 ON notification_preferences (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference ON notification_preferences (user_id, notification_type, channel)');
        $this->addSql('CREATE TABLE notifications (id UUID NOT NULL, type VARCHAR(100) NOT NULL, channel VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, action_url VARCHAR(2048) DEFAULT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED395 ON notifications (user_id)');
        $this->addSql('CREATE INDEX idx_notifications_user_channel_read ON notifications (user_id, channel, read_at)');
        $this->addSql('ALTER TABLE notification_preferences ADD CONSTRAINT FK_3CAA95B4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_preferences DROP CONSTRAINT FK_3CAA95B4A76ED395');
        $this->addSql('ALTER TABLE notifications DROP CONSTRAINT FK_6000B0D3A76ED395');
        $this->addSql('DROP TABLE notification_preferences');
        $this->addSql('DROP TABLE notifications');
    }
}
