<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la duree d analyse en jours pour les seuils dynamiques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat ADD duree_jours INT DEFAULT 7');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analyse_resultat DROP duree_jours');
    }
}
