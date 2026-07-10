<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710171145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document (title VARCHAR(255) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D8698A767E3C61F9 ON document (owner_id)');
        $this->addSql('CREATE TABLE document_key_grant (wrapped_dek TEXT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_390333224BBC2705 ON document_key_grant (version_id)');
        $this->addSql('CREATE INDEX IDX_39033322A76ED395 ON document_key_grant (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_grant_version_user ON document_key_grant (version_id, user_id)');
        $this->addSql('CREATE TABLE document_version (version_number INT NOT NULL, kind VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, content_hash VARCHAR(96) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1B73751F111795A5 ON document_version (storage_key)');
        $this->addSql('CREATE INDEX IDX_1B73751FC33F7837 ON document_version (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_version_number ON document_version (document_id, version_number)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A767E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_key_grant ADD CONSTRAINT FK_390333224BBC2705 FOREIGN KEY (version_id) REFERENCES document_version (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_key_grant ADD CONSTRAINT FK_39033322A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_version ADD CONSTRAINT FK_1B73751FC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP CONSTRAINT FK_D8698A767E3C61F9');
        $this->addSql('ALTER TABLE document_key_grant DROP CONSTRAINT FK_390333224BBC2705');
        $this->addSql('ALTER TABLE document_key_grant DROP CONSTRAINT FK_39033322A76ED395');
        $this->addSql('ALTER TABLE document_version DROP CONSTRAINT FK_1B73751FC33F7837');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE document_key_grant');
        $this->addSql('DROP TABLE document_version');
    }
}
