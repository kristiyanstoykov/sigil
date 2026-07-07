<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706192603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE certificate (serial_number VARCHAR(64) NOT NULL, subject_dn VARCHAR(255) NOT NULL, certificate_pem TEXT NOT NULL, not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, not_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, algorithm_id VARCHAR(50) NOT NULL, token_label VARCHAR(100) NOT NULL, key_label VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, pin_hash VARCHAR(255) NOT NULL, failed_pin_attempts SMALLINT NOT NULL, last_failed_pin_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revocation_reason VARCHAR(100) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_219CDA4AD948EE2 ON certificate (serial_number)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_219CDA4AAD7954A9 ON certificate (token_label)');
        $this->addSql('CREATE INDEX IDX_219CDA4AA76ED395 ON certificate (user_id)');
        $this->addSql('CREATE INDEX idx_certificate_status ON certificate (status)');
        $this->addSql('ALTER TABLE certificate ADD CONSTRAINT FK_219CDA4AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE certificate DROP CONSTRAINT FK_219CDA4AA76ED395');
        $this->addSql('DROP TABLE certificate');
    }
}
