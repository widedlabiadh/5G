<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workflow task decision fields and comment attachment path.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN IF NOT EXISTS decision VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN IF NOT EXISTS step_code VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD COLUMN IF NOT EXISTS step_order INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_comment ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_comment DROP COLUMN IF EXISTS file_path');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN IF EXISTS step_order');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN IF EXISTS step_code');
        $this->addSql('ALTER TABLE ticket_task DROP COLUMN IF EXISTS decision');
    }
}
