<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Delivery receipts (ADR-012): the sealed record of a signature request, plus
 * the per-participant key grants that make it readable.
 *
 * document_id and signing_request_id are plain UUID columns, not foreign keys -
 * a receipt has to outlive its document, since an unsigned request that expires
 * takes the document with it and the receipt is what attests that.
 */
final class Version20260813165044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery receipt + per-participant key grants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE delivery_receipt (document_id UUID NOT NULL, signing_request_id UUID NOT NULL, document_title VARCHAR(255) NOT NULL, document_hash VARCHAR(96) NOT NULL, outcome VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, content_hash VARCHAR(96) NOT NULL, size_bytes INT NOT NULL, sealed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seal_serial_number VARCHAR(64) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5987099BFDB5A7E1 ON delivery_receipt (signing_request_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5987099B111795A5 ON delivery_receipt (storage_key)');
        $this->addSql('CREATE INDEX idx_receipt_document ON delivery_receipt (document_id)');
        $this->addSql('CREATE TABLE delivery_receipt_key_grant (wrapped_dek TEXT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, receipt_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1CFC5F7C2B5CA896 ON delivery_receipt_key_grant (receipt_id)');
        $this->addSql('CREATE INDEX IDX_1CFC5F7CA76ED395 ON delivery_receipt_key_grant (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_grant_receipt_user ON delivery_receipt_key_grant (receipt_id, user_id)');
        $this->addSql('ALTER TABLE delivery_receipt_key_grant ADD CONSTRAINT FK_1CFC5F7C2B5CA896 FOREIGN KEY (receipt_id) REFERENCES delivery_receipt (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_receipt_key_grant ADD CONSTRAINT FK_1CFC5F7CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE delivery_receipt_key_grant DROP CONSTRAINT FK_1CFC5F7C2B5CA896');
        $this->addSql('ALTER TABLE delivery_receipt_key_grant DROP CONSTRAINT FK_1CFC5F7CA76ED395');
        $this->addSql('DROP TABLE delivery_receipt');
        $this->addSql('DROP TABLE delivery_receipt_key_grant');
    }
}
