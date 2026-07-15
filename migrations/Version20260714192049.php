<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714192049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Certificate.heldUntil - owner-initiated temporary hold (certificateHold), lifts itself once the moment passes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certificate ADD held_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certificate DROP held_until');
    }
}
