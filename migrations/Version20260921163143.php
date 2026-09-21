<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921163143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TOTP replay guard (RFC 6238 §5.2): the last accepted code and when, on the user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_totp_code VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD last_totp_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP last_totp_code');
        $this->addSql('ALTER TABLE users DROP last_totp_used_at');
    }
}
