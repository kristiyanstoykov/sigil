<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920100641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One signing request and one delivery per document, ever - unique indexes backing the service-level checks.';
    }

    public function up(Schema $schema): void
    {
        // Rows from before the once-only rules (2026-08-15 / 2026-08-18) would
        // make the index creation fail halfway; say so up front instead.
        foreach (['signing_request', 'delivery'] as $table) {
            $duplicates = (int) $this->connection->fetchOne(
                sprintf('SELECT count(*) FROM (SELECT document_id FROM %s GROUP BY document_id HAVING count(*) > 1) d', $table),
            );
            $this->abortIf($duplicates > 0, sprintf(
                '%d document(s) have more than one %s row. Resolve them first (keep the oldest), then rerun.',
                $duplicates,
                $table,
            ));
        }

        $this->addSql('DROP INDEX idx_3781ec10c33f7837');
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_document ON delivery (document_id)');
        $this->addSql('DROP INDEX idx_a6d261d3c33f7837');
        $this->addSql('CREATE UNIQUE INDEX uniq_signing_request_document ON signing_request (document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_delivery_document');
        $this->addSql('CREATE INDEX idx_3781ec10c33f7837 ON delivery (document_id)');
        $this->addSql('DROP INDEX uniq_signing_request_document');
        $this->addSql('CREATE INDEX idx_a6d261d3c33f7837 ON signing_request (document_id)');
    }
}
