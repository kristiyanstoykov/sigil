<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017170725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion (ADR-015 §1-3): tombstone columns, archived KEKs, and the signer-deleted mark.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE archived_user_key (wrapped_kek TEXT NOT NULL, retain_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9B7CB431A76ED395 ON archived_user_key (user_id)');
        $this->addSql('CREATE INDEX idx_archived_user_key_retain_until ON archived_user_key (retain_until)');
        $this->addSql('ALTER TABLE archived_user_key ADD CONSTRAINT FK_9B7CB431A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signing_request_signer ADD account_deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_fingerprint VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD recovery_code_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9EACB09FD ON users (email_fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archived_user_key DROP CONSTRAINT FK_9B7CB431A76ED395');
        $this->addSql('DROP TABLE archived_user_key');
        $this->addSql('ALTER TABLE signing_request_signer DROP account_deleted_at');
        $this->addSql('DROP INDEX UNIQ_1483A5E9EACB09FD');
        $this->addSql('ALTER TABLE users DROP deleted_at');
        $this->addSql('ALTER TABLE users DROP email_fingerprint');
        $this->addSql('ALTER TABLE users DROP recovery_code_hash');
    }
}
