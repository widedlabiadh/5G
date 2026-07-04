<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index for historical traffic curve lookups by site and date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trafic_site_date_heure ON trafic_historique (site, date_heure)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_trafic_site_date_heure');
    }
}
