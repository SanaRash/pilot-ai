<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket conversation messages and client-safe knowledge articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ticket_message (id SERIAL NOT NULL, ticket_id INT NOT NULL, author_type VARCHAR(20) NOT NULL, author_user_id INT DEFAULT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT CHK_TICKET_MESSAGE_AUTHOR CHECK ((author_type = 'CLIENT' AND author_user_id IS NOT NULL) OR (author_type = 'BOT' AND author_user_id IS NULL)))");
        $this->addSql('CREATE INDEX IDX_TICKET_MESSAGE_TICKET ON ticket_message (ticket_id)');
        $this->addSql('CREATE INDEX IDX_TICKET_MESSAGE_AUTHOR ON ticket_message (author_user_id)');
        $this->addSql('ALTER TABLE ticket_message ADD CONSTRAINT FK_TICKET_MESSAGE_TICKET FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE ticket_message ADD CONSTRAINT FK_TICKET_MESSAGE_AUTHOR FOREIGN KEY (author_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE knowledge_article (id SERIAL NOT NULL, category_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, keywords JSON NOT NULL, is_active BOOLEAN NOT NULL, is_client_safe BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_KNOWLEDGE_ARTICLE_CATEGORY ON knowledge_article (category_id)');
        $this->addSql('ALTER TABLE knowledge_article ADD CONSTRAINT FK_KNOWLEDGE_ARTICLE_CATEGORY FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_message DROP CONSTRAINT FK_TICKET_MESSAGE_TICKET');
        $this->addSql('ALTER TABLE ticket_message DROP CONSTRAINT FK_TICKET_MESSAGE_AUTHOR');
        $this->addSql('DROP TABLE ticket_message');
        $this->addSql('ALTER TABLE knowledge_article DROP CONSTRAINT FK_KNOWLEDGE_ARTICLE_CATEGORY');
        $this->addSql('DROP TABLE knowledge_article');
    }
}
