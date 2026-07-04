<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds indexes to optimize import performance
 */
final class Version20260608113647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds indexes to analyse_resultat and capacite_site to optimize import performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_analyse_site ON analyse_resultat (site)');
        $this->addSql('CREATE INDEX idx_analyse_site_fdd ON analyse_resultat (site_fdd)');
        $this->addSql('CREATE INDEX idx_analyse_site_tdd ON analyse_resultat (site_tdd)');
        $this->addSql('CREATE INDEX idx_analyse_type_trans ON analyse_resultat (type_trans)');
        
        $this->addSql('CREATE INDEX idx_capacite_site_code ON capacite_site (site)');
        $this->addSql('CREATE INDEX idx_capacite_type_trans ON capacite_site (type_trans)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_analyse_site');
        $this->addSql('DROP INDEX idx_analyse_site_fdd');
        $this->addSql('DROP INDEX idx_analyse_site_tdd');
        $this->addSql('DROP INDEX idx_analyse_type_trans');
        
        $this->addSql('DROP INDEX idx_capacite_site_code');
        $this->addSql('DROP INDEX idx_capacite_type_trans');
    }
}
