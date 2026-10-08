<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add requester_email to email-ingested tickets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD requester_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP requester_email');
    }
}
