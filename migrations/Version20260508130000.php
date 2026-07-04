<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les occurrences separees TDD et FDD aux resultats d analyse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat ADD COLUMN IF NOT EXISTS nombre_occurrences_tdd INT DEFAULT 0');
        $this->addSql('ALTER TABLE analyse_resultat ADD COLUMN IF NOT EXISTS nombre_occurrences_fdd INT DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat DROP COLUMN IF EXISTS nombre_occurrences_tdd');
        $this->addSql('ALTER TABLE analyse_resultat DROP COLUMN IF EXISTS nombre_occurrences_fdd');
    }
}
