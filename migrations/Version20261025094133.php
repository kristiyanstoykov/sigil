<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261025094133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account closure records (ADR-015 §3): the sealed record of each deletion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_closure_record (storage_key VARCHAR(255) NOT NULL, wrapped_dek TEXT NOT NULL, content_hash VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, sealed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seal_serial_number VARCHAR(64) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_73EA1F2D111795A5 ON account_closure_record (storage_key)');
        $this->addSql('CREATE INDEX IDX_73EA1F2DA76ED395 ON account_closure_record (user_id)');
        $this->addSql('ALTER TABLE account_closure_record ADD CONSTRAINT FK_73EA1F2DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_closure_record DROP CONSTRAINT FK_73EA1F2DA76ED395');
        $this->addSql('DROP TABLE account_closure_record');
    }
}
