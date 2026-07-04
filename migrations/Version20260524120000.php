<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DropCong KPI flag to analyse_resultat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat ADD COLUMN IF NOT EXISTS drop_cong_kpi BOOLEAN DEFAULT false');
        $this->addSql('UPDATE analyse_resultat SET drop_cong_kpi = false WHERE drop_cong_kpi IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat DROP COLUMN IF EXISTS drop_cong_kpi');
    }
}
