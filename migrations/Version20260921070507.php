<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921070507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit entries record the chain scheme they were hashed under (SHA256/v1 for every existing row).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log_entry ADD hash_scheme VARCHAR(32) DEFAULT \'SHA256/v1\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log_entry DROP hash_scheme');
    }
}
