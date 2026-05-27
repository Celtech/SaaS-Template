<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527044002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce append-only immutability on audit_log via a PostgreSQL trigger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_log_immutable()
                RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_log rows are immutable and may not be updated or deleted';
                END;
                $$
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TRIGGER audit_log_no_update
                BEFORE UPDATE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_immutable()
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TRIGGER audit_log_no_delete
                BEFORE DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_immutable()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS audit_log_no_update ON audit_log');
        $this->addSql('DROP TRIGGER IF EXISTS audit_log_no_delete ON audit_log');
        $this->addSql('DROP FUNCTION IF EXISTS audit_log_immutable()');
    }
}
