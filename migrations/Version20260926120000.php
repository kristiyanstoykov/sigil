<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitation-only registration: the allowlist, seeded with every existing account.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registration_allowlist (id UUID NOT NULL, email VARCHAR(180) NOT NULL, added_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_52C34A1DE7927C74 ON registration_allowlist (email)');
        // Everyone who already has an account stays able to have one.
        $this->addSql('INSERT INTO registration_allowlist (id, email, added_by, created_at) SELECT gen_random_uuid(), LOWER(email), NULL, NOW() FROM users ON CONFLICT (email) DO NOTHING');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registration_allowlist');
    }
}
