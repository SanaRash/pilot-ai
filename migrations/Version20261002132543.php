<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002132543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client visibility flag to interventions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention ADD is_client_visible BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention DROP is_client_visible');
    }
}