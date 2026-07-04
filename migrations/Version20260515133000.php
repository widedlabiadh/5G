<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UMPT site import storage and snapshot fields on workflow sites.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS umpt_site (id SERIAL NOT NULL, site VARCHAR(120) NOT NULL, card_type VARCHAR(30) NOT NULL, swap_required BOOLEAN NOT NULL, swap_comment TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source_file VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_umpt_site ON umpt_site (site)');
        $this->addSql('ALTER TABLE ticket_site ADD COLUMN IF NOT EXISTS umpt_type VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_site ADD COLUMN IF NOT EXISTS swap_required BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_site ADD COLUMN IF NOT EXISTS swap_comment TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_site DROP COLUMN IF EXISTS swap_comment');
        $this->addSql('ALTER TABLE ticket_site DROP COLUMN IF EXISTS swap_required');
        $this->addSql('ALTER TABLE ticket_site DROP COLUMN IF EXISTS umpt_type');
        $this->addSql('DROP TABLE IF EXISTS umpt_site');
    }
}
