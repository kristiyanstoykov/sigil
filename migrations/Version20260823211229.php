<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The notification inbox. read_at is a per-recipient inbox flag and nothing more:
 * it is never read by the sender and never enters the audit log or a receipt, so
 * it is not a read receipt (ADR-012 records consignment, not retrieval).
 */
final class Version20260823211229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the notification table (in-app inbox).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (type VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, body TEXT DEFAULT NULL, url VARCHAR(512) NOT NULL, document_id UUID DEFAULT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recipient_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BF5476CAE92F8F78 ON notification (recipient_id)');
        $this->addSql('CREATE INDEX idx_notification_inbox ON notification (recipient_id, created_at)');
        $this->addSql('CREATE INDEX idx_notification_unread ON notification (recipient_id, read_at)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAE92F8F78');
        $this->addSql('DROP TABLE notification');
    }
}
