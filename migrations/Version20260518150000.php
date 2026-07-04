<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add chat_conversation table for assistant server-side history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_conversation (
            id SERIAL NOT NULL,
            user_id INT NOT NULL,
            public_id VARCHAR(36) NOT NULL,
            title VARCHAR(255) NOT NULL,
            messages JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_conversation_public_id ON chat_conversation (public_id)');
        $this->addSql('CREATE INDEX idx_chat_conversation_user ON chat_conversation (user_id)');
        $this->addSql('ALTER TABLE chat_conversation ADD CONSTRAINT fk_chat_conversation_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_conversation DROP CONSTRAINT fk_chat_conversation_user');
        $this->addSql('DROP TABLE chat_conversation');
    }
}
