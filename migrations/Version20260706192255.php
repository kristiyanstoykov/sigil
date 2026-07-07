<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706192255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log_entry (sequence BIGINT NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_id UUID DEFAULT NULL, action VARCHAR(100) NOT NULL, subject_type VARCHAR(100) DEFAULT NULL, subject_id VARCHAR(64) DEFAULT NULL, severity VARCHAR(20) NOT NULL, payload JSON NOT NULL, previous_hash VARCHAR(64) NOT NULL, entry_hash VARCHAR(64) NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2D938A25286D72B ON audit_log_entry (sequence)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2D938A2759A1AAB ON audit_log_entry (entry_hash)');
        $this->addSql('CREATE INDEX idx_audit_action ON audit_log_entry (action)');
        $this->addSql('CREATE INDEX idx_audit_actor ON audit_log_entry (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_subject ON audit_log_entry (subject_type, subject_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_log_entry');
    }
}
