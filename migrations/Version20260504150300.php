<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260504150300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_analyse_stats_json');
        $this->addSql('DROP INDEX idx_analyse_tdd');
        $this->addSql('DROP INDEX idx_analyse_fdd');
        $this->addSql('ALTER TABLE analyse_resultat ADD capacite_mbps NUMERIC(15, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat DROP stats_json');
        $this->addSql('ALTER TABLE analyse_resultat DROP max_trafic_fdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP max_trafic_tdd');
        $this->addSql('DROP INDEX idx_port_data_site');
        $this->addSql('DROP INDEX idx_trafic_max_speed');
        $this->addSql('DROP INDEX idx_trafic_date');
        $this->addSql('DROP INDEX idx_trafic_site');
        $this->addSql('DROP INDEX idx_type_liaison_site');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyse_resultat ADD stats_json JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE analyse_resultat ADD max_trafic_fdd NUMERIC(15, 4) DEFAULT \'0\'');
        $this->addSql('ALTER TABLE analyse_resultat ADD max_trafic_tdd NUMERIC(15, 4) DEFAULT \'0\'');
        $this->addSql('ALTER TABLE analyse_resultat DROP capacite_mbps');
        $this->addSql('CREATE INDEX idx_analyse_stats_json ON analyse_resultat (stats_json)');
        $this->addSql('CREATE INDEX idx_analyse_tdd ON analyse_resultat (max_trafic_tdd)');
        $this->addSql('CREATE INDEX idx_analyse_fdd ON analyse_resultat (max_trafic_fdd)');
        $this->addSql('CREATE INDEX idx_port_data_site ON port_data (site)');
        $this->addSql('CREATE INDEX idx_trafic_max_speed ON trafic_historique (max_speed)');
        $this->addSql('CREATE INDEX idx_trafic_date ON trafic_historique (date_importation)');
        $this->addSql('CREATE INDEX idx_trafic_site ON trafic_historique (site)');
        $this->addSql('CREATE INDEX idx_type_liaison_site ON type_liaison_data (site)');
    }
}
