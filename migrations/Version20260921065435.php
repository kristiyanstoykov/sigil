<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921065435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Content fingerprints become self-describing ("HMAC-SHA384/v1:<hex>"): widen the columns and prefix the existing keyed digests, which were all HMAC-SHA384/v1 by construction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_receipt ALTER document_hash TYPE VARCHAR(128)');
        $this->addSql('ALTER TABLE delivery_receipt ALTER content_hash TYPE VARCHAR(128)');
        $this->addSql('ALTER TABLE document_version ALTER content_hash TYPE VARCHAR(128)');
        // Every stored value so far is a bare hex HMAC-SHA384/v1 digest; an empty
        // document_hash (a receipt for an erased document) stays empty.
        foreach (['document_version.content_hash', 'delivery_receipt.document_hash', 'delivery_receipt.content_hash'] as $column) {
            [$table, $name] = explode('.', $column);
            $this->addSql(sprintf("UPDATE %s SET %s = 'HMAC-SHA384/v1:' || %s WHERE %s <> '' AND position(':' in %s) = 0", $table, $name, $name, $name, $name));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['document_version.content_hash', 'delivery_receipt.document_hash', 'delivery_receipt.content_hash'] as $column) {
            [$table, $name] = explode('.', $column);
            $this->addSql(sprintf("UPDATE %s SET %s = substring(%s from position(':' in %s) + 1) WHERE position(':' in %s) > 0", $table, $name, $name, $name, $name));
        }
        $this->addSql('ALTER TABLE delivery_receipt ALTER document_hash TYPE VARCHAR(96)');
        $this->addSql('ALTER TABLE delivery_receipt ALTER content_hash TYPE VARCHAR(96)');
        $this->addSql('ALTER TABLE document_version ALTER content_hash TYPE VARCHAR(96)');
    }
}
