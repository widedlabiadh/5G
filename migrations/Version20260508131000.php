<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recalcule les occurrences TDD et FDD de la derniere analyse depuis le dernier fichier trafic importe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            WITH latest AS (
                SELECT MAX(date_analyse) AS date_analyse
                FROM analyse_resultat
            ),
            latest_import AS (
                SELECT MAX(date_importation) AS date_importation
                FROM trafic_historique
            ),
            target_sites AS (
                SELECT DISTINCT UPPER(site_tdd) AS site_key
                FROM analyse_resultat ar_target, latest
                WHERE ar_target.date_analyse = latest.date_analyse
                  AND ar_target.site_tdd IS NOT NULL
                UNION
                SELECT DISTINCT UPPER(site_fdd) AS site_key
                FROM analyse_resultat ar_target, latest
                WHERE ar_target.date_analyse = latest.date_analyse
                  AND ar_target.site_fdd IS NOT NULL
            ),
            site_max AS (
                SELECT UPPER(th.site) AS site_key, MAX(th.max_speed) AS max_speed
                FROM trafic_historique th
                INNER JOIN latest_import li ON th.date_importation = li.date_importation
                INNER JOIN target_sites ts ON ts.site_key = UPPER(th.site)
                GROUP BY UPPER(th.site)
            ),
            site_occ AS (
                SELECT UPPER(th.site) AS site_key, COUNT(*)::int AS occurrences
                FROM trafic_historique th
                INNER JOIN latest_import li ON th.date_importation = li.date_importation
                INNER JOIN site_max sm ON sm.site_key = UPPER(th.site)
                WHERE th.max_speed >= sm.max_speed * 0.9
                GROUP BY UPPER(th.site)
            ),
            calc AS (
                SELECT
                    ar2.id,
                    COALESCE(tdd.occurrences, 0) AS occurrences_tdd,
                    COALESCE(fdd.occurrences, 0) AS occurrences_fdd
                FROM analyse_resultat ar2
                INNER JOIN latest ON ar2.date_analyse = latest.date_analyse
                LEFT JOIN site_occ tdd ON tdd.site_key = UPPER(ar2.site_tdd)
                LEFT JOIN site_occ fdd ON fdd.site_key = UPPER(ar2.site_fdd)
            )
            UPDATE analyse_resultat ar
            SET
                nombre_occurrences_tdd = calc.occurrences_tdd,
                nombre_occurrences_fdd = calc.occurrences_fdd
            FROM calc
            WHERE ar.id = calc.id
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE analyse_resultat SET nombre_occurrences_tdd = 0, nombre_occurrences_fdd = 0 WHERE date_analyse = (SELECT MAX(date_analyse) FROM analyse_resultat)');
    }
}
