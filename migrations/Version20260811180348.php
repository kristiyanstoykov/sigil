<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811180348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SigningRequest + SigningRequestSigner - ordered, sequential multi-signer requests (ADR-007)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE signing_request (status VARCHAR(255) NOT NULL, deadline TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_id UUID NOT NULL, requester_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A6D261D3C33F7837 ON signing_request (document_id)');
        $this->addSql('CREATE INDEX IDX_A6D261D3ED442CF4 ON signing_request (requester_id)');
        $this->addSql('CREATE INDEX idx_signing_request_status ON signing_request (status)');
        $this->addSql('CREATE TABLE signing_request_signer (position INT NOT NULL, signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, request_id UUID NOT NULL, user_id UUID NOT NULL, version_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D1EA25D6427EB8A5 ON signing_request_signer (request_id)');
        $this->addSql('CREATE INDEX IDX_D1EA25D6A76ED395 ON signing_request_signer (user_id)');
        $this->addSql('CREATE INDEX IDX_D1EA25D64BBC2705 ON signing_request_signer (version_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_signer_position ON signing_request_signer (request_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_signer_user ON signing_request_signer (request_id, user_id)');
        $this->addSql('ALTER TABLE signing_request ADD CONSTRAINT FK_A6D261D3C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signing_request ADD CONSTRAINT FK_A6D261D3ED442CF4 FOREIGN KEY (requester_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signing_request_signer ADD CONSTRAINT FK_D1EA25D6427EB8A5 FOREIGN KEY (request_id) REFERENCES signing_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signing_request_signer ADD CONSTRAINT FK_D1EA25D6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signing_request_signer ADD CONSTRAINT FK_D1EA25D64BBC2705 FOREIGN KEY (version_id) REFERENCES document_version (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signing_request DROP CONSTRAINT FK_A6D261D3C33F7837');
        $this->addSql('ALTER TABLE signing_request DROP CONSTRAINT FK_A6D261D3ED442CF4');
        $this->addSql('ALTER TABLE signing_request_signer DROP CONSTRAINT FK_D1EA25D6427EB8A5');
        $this->addSql('ALTER TABLE signing_request_signer DROP CONSTRAINT FK_D1EA25D6A76ED395');
        $this->addSql('ALTER TABLE signing_request_signer DROP CONSTRAINT FK_D1EA25D64BBC2705');
        $this->addSql('DROP TABLE signing_request');
        $this->addSql('DROP TABLE signing_request_signer');
    }
}
