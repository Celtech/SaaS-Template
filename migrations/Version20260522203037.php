<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522203037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create security_settings singleton table for DB-managed lockout thresholds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE security_settings (id INT NOT NULL, max_failed_attempts INT NOT NULL, lockout_minutes INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO security_settings (id, max_failed_attempts, lockout_minutes) VALUES (1, 5, 15)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE security_settings');
    }
}
