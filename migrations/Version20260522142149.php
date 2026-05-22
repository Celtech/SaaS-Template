<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522142149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at to email_verification_tokens for resend rate limiting';
    }

    public function up(Schema $schema): void
    {
        // Backfill existing rows using expires_at - 24h before dropping the default
        $this->addSql("ALTER TABLE email_verification_tokens ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()");
        $this->addSql("UPDATE email_verification_tokens SET created_at = expires_at - INTERVAL '24 hours'");
        $this->addSql("ALTER TABLE email_verification_tokens ALTER COLUMN created_at DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_verification_tokens DROP created_at');
    }
}
