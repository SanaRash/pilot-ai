<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004211943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique nullable email Message-ID to tickets for ingestion idempotency.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD message_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TICKET_MESSAGE_ID ON ticket (message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_TICKET_MESSAGE_ID');
        $this->addSql('ALTER TABLE ticket DROP message_id');
    }
}
