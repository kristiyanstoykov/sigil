<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011091034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data export (ADR-015 §7) and one-time temporary downloads (§8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE data_export (status VARCHAR(20) NOT NULL, file_count INT DEFAULT 0 NOT NULL, part_count INT DEFAULT 0 NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2272278D7E3C61F9 ON data_export (owner_id)');
        $this->addSql('CREATE INDEX idx_data_export_owner ON data_export (owner_id, created_at)');
        $this->addSql('CREATE TABLE temp_download (group_id UUID DEFAULT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, storage_key VARCHAR(255) NOT NULL, wrapped_dek TEXT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1812C6917E3C61F9 ON temp_download (owner_id)');
        $this->addSql('CREATE INDEX idx_temp_download_owner_group ON temp_download (owner_id, group_id)');
        $this->addSql('CREATE INDEX idx_temp_download_expires ON temp_download (expires_at)');
        $this->addSql('ALTER TABLE data_export ADD CONSTRAINT FK_2272278D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE temp_download ADD CONSTRAINT FK_1812C6917E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_export DROP CONSTRAINT FK_2272278D7E3C61F9');
        $this->addSql('ALTER TABLE temp_download DROP CONSTRAINT FK_1812C6917E3C61F9');
        $this->addSql('DROP TABLE data_export');
        $this->addSql('DROP TABLE temp_download');
    }
}
