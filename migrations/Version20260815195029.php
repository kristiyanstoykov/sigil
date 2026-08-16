<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Delivery: serving a document on people (ADR-012). Adds the delivery and
 * delivery_recipient tables, and generalises delivery_receipt so a receipt can
 * attest either a signature request or a delivery.
 */
final class Version20260815195029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery tables, and delivery_receipt gains a source so it can attest a delivery too.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE delivery (note VARCHAR(500) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_id UUID NOT NULL, sender_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3781EC10C33F7837 ON delivery (document_id)');
        $this->addSql('CREATE INDEX IDX_3781EC10F624B39D ON delivery (sender_id)');
        $this->addSql('CREATE TABLE delivery_recipient (delivered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivery_id UUID NOT NULL, user_id UUID NOT NULL, version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2C61B7DB12136921 ON delivery_recipient (delivery_id)');
        $this->addSql('CREATE INDEX IDX_2C61B7DBA76ED395 ON delivery_recipient (user_id)');
        $this->addSql('CREATE INDEX IDX_2C61B7DB4BBC2705 ON delivery_recipient (version_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_recipient ON delivery_recipient (delivery_id, user_id)');
        $this->addSql('ALTER TABLE delivery ADD CONSTRAINT FK_3781EC10C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery ADD CONSTRAINT FK_3781EC10F624B39D FOREIGN KEY (sender_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_recipient ADD CONSTRAINT FK_2C61B7DB12136921 FOREIGN KEY (delivery_id) REFERENCES delivery (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_recipient ADD CONSTRAINT FK_2C61B7DBA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_recipient ADD CONSTRAINT FK_2C61B7DB4BBC2705 FOREIGN KEY (version_id) REFERENCES document_version (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Every receipt that exists today was sealed for a signature request, so
        // the column goes in nullable, is backfilled, and only then becomes NOT
        // NULL - adding it NOT NULL outright fails on a table with rows.
        $this->addSql('DROP INDEX uniq_5987099bfdb5a7e1');
        $this->addSql('ALTER TABLE delivery_receipt ADD source VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE delivery_receipt SET source = 'signing_request' WHERE source IS NULL");
        $this->addSql('ALTER TABLE delivery_receipt ALTER COLUMN source SET NOT NULL');
        $this->addSql('ALTER TABLE delivery_receipt RENAME COLUMN signing_request_id TO source_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5987099B953C1C61 ON delivery_receipt (source_id)');
    }

    public function down(Schema $schema): void
    {
        // Deliveries and their receipts are dropped with the tables; a receipt
        // that attested a delivery has no home in the old shape.
        $this->addSql("DELETE FROM delivery_receipt WHERE source = 'delivery'");
        $this->addSql('ALTER TABLE delivery DROP CONSTRAINT FK_3781EC10C33F7837');
        $this->addSql('ALTER TABLE delivery DROP CONSTRAINT FK_3781EC10F624B39D');
        $this->addSql('ALTER TABLE delivery_recipient DROP CONSTRAINT FK_2C61B7DB12136921');
        $this->addSql('ALTER TABLE delivery_recipient DROP CONSTRAINT FK_2C61B7DBA76ED395');
        $this->addSql('ALTER TABLE delivery_recipient DROP CONSTRAINT FK_2C61B7DB4BBC2705');
        $this->addSql('DROP TABLE delivery');
        $this->addSql('DROP TABLE delivery_recipient');
        $this->addSql('DROP INDEX UNIQ_5987099B953C1C61');
        $this->addSql('ALTER TABLE delivery_receipt DROP source');
        $this->addSql('ALTER TABLE delivery_receipt RENAME COLUMN source_id TO signing_request_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_5987099bfdb5a7e1 ON delivery_receipt (signing_request_id)');
    }
}
