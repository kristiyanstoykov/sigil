<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A signer can refuse (ADR-012): being asked to sign is not being obliged to.
 * The reason is nullable because a refusal needs no justification.
 */
final class Version20260813193910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signer decline: declined_at + optional reason';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signing_request_signer ADD declined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE signing_request_signer ADD decline_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signing_request_signer DROP declined_at');
        $this->addSql('ALTER TABLE signing_request_signer DROP decline_reason');
    }
}
