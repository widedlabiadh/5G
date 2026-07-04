<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Excel-managed operational fields to managed_site.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS max_tdd NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS max_fdd NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS capacite_tdd_mbps NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS capacite_fdd_mbps NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS taux_utilisation NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS nombre_occurrences INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS nombre_occurrences_tdd INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS nombre_occurrences_fdd INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS decision_statut VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS etat VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE managed_site ADD COLUMN IF NOT EXISTS date_max TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS date_max');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS etat');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS decision_statut');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS nombre_occurrences_fdd');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS nombre_occurrences_tdd');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS nombre_occurrences');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS taux_utilisation');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS capacite_fdd_mbps');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS capacite_tdd_mbps');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS max_fdd');
        $this->addSql('ALTER TABLE managed_site DROP COLUMN IF EXISTS max_tdd');
    }
}
