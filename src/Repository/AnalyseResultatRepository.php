<?php
// src/Repository/AnalyseResultatRepository.php

namespace App\Repository;

use App\Entity\AnalyseResultat;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyseResultat>
 */
class AnalyseResultatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyseResultat::class);
    }

    private function dynamicCriticalThresholdSql(): string
    {
        return 'CEIL(50.0 * COALESCE(NULLIF(duree_jours, 0), 7) / 7.0)';
    }

    /**
     * Retourne la dernière date d'analyse disponible.
     */
    public function findLastAnalyseDate(): ?\DateTimeInterface
    {
        $result = $this->createQueryBuilder('a')
            ->select('a.dateAnalyse')
            ->orderBy('a.dateAnalyse', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['dateAnalyse'] : null;
    }

    /**
     * Sites critiques selon la durée d'analyse.
     */
    public function findSitesCritiques(int $limit = 20): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT * FROM analyse_resultat
             WHERE nombre_occurrences >= ' . $this->dynamicCriticalThresholdSql() . '
             ORDER BY nombre_occurrences DESC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );

        $sites = [];
        foreach ($rows as $row) {
            $site = new AnalyseResultat();
            $site->setSite((string) ($row['site'] ?? ''));
            $site->setClassification((string) ($row['classification'] ?? ''));
            $site->setTypeTrans($row['type_trans'] ?? null);
            $site->setMaxTrafic((float) ($row['max_trafic'] ?? 0));
            $site->setSeuilCritique((float) ($row['seuil_critique'] ?? 0));
            $site->setNombreOccurrences((int) ($row['nombre_occurrences'] ?? 0));
            $site->setTotalMeasures((int) ($row['total_measures'] ?? 0));
            $site->setDateAnalyse(new \DateTime((string) ($row['date_analyse'] ?? 'now')));
            $site->setDureeJours((int) ($row['duree_jours'] ?? 7));
            $sites[] = $site;
        }

        return $sites;
    }

    /**
     * Statistiques simples par type (FO, FH, BACKBONE) – version legacy.
     * @deprecated Utilisez plutôt StatisticsService::getStatsByType()
     */
    public function getStatsByTypeLegacy(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $fo = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['FO']);
        $fh = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['FH']);
        $backbone = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['BACKBONE']);
        
        return [
            'fo' => $fo,
            'fh' => $fh,
            'backbone' => $backbone,
        ];
    }

    /**
     * Statistiques détaillées par type (FO, FH ou BACKBONE).
     * Utilisé par StatisticsService.
     */
    public function getStatsByType(string $type): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $lastDate = $this->findLastAnalyseDate();
        if (!$lastDate) {
            return [
                'total' => 0,
                'total_occ' => 0,
                'max_trafic' => 0,
                'sites_critiques' => 0
            ];
        }

        if ($type === 'BACKBONE') {
            $total = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat 
                 WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date',
                ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]
            );
            $occ = $conn->fetchOne(
                'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat 
                 WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date',
                ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]
            );
            $max = $conn->fetchOne(
                'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat 
                 WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date',
                ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]
            );
            $critiques = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat 
                 WHERE (type_trans = :exact OR type_trans LIKE :pattern)
                   AND nombre_occurrences >= ' . $this->dynamicCriticalThresholdSql() . '
                   AND date_analyse = :date',
                ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]
            );
        } else {
            $t = strtoupper($type);
            $pattern = match ($t) {
                'FO' => 'FO%',
                'FH' => 'FH%',
                default => $t,
            };
            $total = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date',
                ['type' => $pattern, 'date' => $lastDate]
            );
            $occ = $conn->fetchOne(
                'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date',
                ['type' => $pattern, 'date' => $lastDate]
            );
            $max = $conn->fetchOne(
                'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date',
                ['type' => $pattern, 'date' => $lastDate]
            );
            $critiques = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type
                 AND nombre_occurrences >= ' . $this->dynamicCriticalThresholdSql() . '
                 AND date_analyse = :date',
                ['type' => $pattern, 'date' => $lastDate]
            );
        }

        return [
            'total' => (int)$total,
            'total_occ' => (int)$occ,
            'max_trafic' => (float)$max,
            'sites_critiques' => (int)$critiques,
        ];
    }

    /**
     * Récupère tous les sites avec leurs coordonnées GPS pour la dernière analyse.
     */
    public function findSitesWithGps(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $lastDate = $this->findLastAnalyseDate();
        if (!$lastDate) {
            return [];
        }
        $lastDateValue = $lastDate->format('Y-m-d H:i:s');

        return $conn->fetchAllAssociative(
            'SELECT id, site, classification, type_trans, max_trafic, seuil_critique, 
                    nombre_occurrences, latitude, longitude, date_analyse, duree_jours 
             FROM analyse_resultat 
             WHERE date_analyse = :date 
             ORDER BY site',
            ['date' => $lastDateValue]
        );
    }

    /**
     * Retourne les sites de la dernière analyse pour alimenter le CRUD superuser.
     */
    public function findLatestSitesSnapshot(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $lastDate = $this->findLastAnalyseDate();
        if (!$lastDate) {
            return [];
        }
        $lastDateValue = $lastDate->format('Y-m-d H:i:s');

        return $conn->fetchAllAssociative(
            'SELECT DISTINCT ON (site)
                site,
                classification,
                type_trans,
                max_trafic,
                max_trafic_tdd,
                max_trafic_fdd,
                capacite_mbps,
                nombre_occurrences,
                nombre_occurrences_tdd,
                nombre_occurrences_fdd,
                date_max,
                duree_jours,
                latitude,
                longitude,
                date_analyse
             FROM analyse_resultat
             WHERE date_analyse = :date
             ORDER BY site, id DESC',
            ['date' => $lastDateValue]
        );
    }

    public function findLatestSnapshotBySite(string $site): ?array
    {
        $conn = $this->getEntityManager()->getConnection();
        $lastDate = $this->findLastAnalyseDate();
        if (!$lastDate) {
            return null;
        }
        $lastDateValue = $lastDate->format('Y-m-d H:i:s');

        $row = $conn->fetchAssociative(
            'SELECT DISTINCT ON (site)
                site,
                classification,
                type_trans,
                max_trafic,
                max_trafic_tdd,
                max_trafic_fdd,
                capacite_mbps,
                nombre_occurrences,
                nombre_occurrences_tdd,
                nombre_occurrences_fdd,
                date_max,
                duree_jours,
                latitude,
                longitude,
                date_analyse
             FROM analyse_resultat
             WHERE date_analyse = :date AND LOWER(site) = LOWER(:site)
             ORDER BY site, id DESC',
            ['date' => $lastDateValue, 'site' => $site]
        );

        return $row ?: null;
    }

    public function findLatestKnownSnapshotBySite(string $site): ?array
    {
        $site = trim($site);
        if ($site === '') {
            return null;
        }

        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT DISTINCT ON (LOWER(site))
                site,
                classification,
                type_trans,
                max_trafic,
                max_trafic_tdd,
                max_trafic_fdd,
                capacite_mbps,
                nombre_occurrences,
                nombre_occurrences_tdd,
                nombre_occurrences_fdd,
                date_max,
                duree_jours,
                latitude,
                longitude,
                date_analyse
             FROM analyse_resultat
             WHERE LOWER(site) = LOWER(:site)
             ORDER BY LOWER(site),
                CASE
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                     AND latitude <> 0 AND longitude <> 0 THEN 0
                    ELSE 1
                END,
                date_analyse DESC,
                id DESC',
            ['site' => $site]
        );

        return $row ?: null;
    }

    public function findReferenceSites(int $limit = 800): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (LOWER(site))
                site,
                classification,
                type_trans,
                max_trafic,
                capacite_mbps,
                nombre_occurrences,
                nombre_occurrences_tdd,
                nombre_occurrences_fdd,
                date_max,
                duree_jours,
                latitude,
                longitude,
                date_analyse
             FROM analyse_resultat
             WHERE site IS NOT NULL AND TRIM(site) <> \'\'
             ORDER BY LOWER(site),
                CASE
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                     AND latitude <> 0 AND longitude <> 0 THEN 0
                    ELSE 1
                END,
                date_analyse DESC,
                id DESC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );
    }

    public function findWorkflowChoices(int $limit = 800): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (LOWER(a.site))
                a.id,
                a.site,
                a.classification,
                a.type_trans AS "typeTrans",
                a.max_trafic AS "maxTrafic",
                a.seuil_critique AS "seuilCritique",
                a.nombre_occurrences AS "nombreOccurrences",
                u.card_type AS "umptType",
                u.swap_comment AS "swapComment",
                u.swap_required AS "swapRequired"
             FROM analyse_resultat a
             LEFT JOIN umpt_site u ON u.site = UPPER(TRIM(a.site))
             WHERE a.site IS NOT NULL
               AND TRIM(a.site) <> \'\'
               AND a.date_analyse = (SELECT MAX(date_analyse) FROM analyse_resultat)
               AND a.nombre_occurrences >= ' . $this->dynamicCriticalThresholdSql() . '
             ORDER BY LOWER(a.site), a.date_analyse DESC, a.id DESC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );
    }

    public function findWorkflowSitesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT
                id,
                site,
                classification,
                type_trans AS "typeTrans",
                max_trafic AS "maxTrafic"
             FROM analyse_resultat
             WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $orderedRows = [];
        foreach ($ids as $id) {
            if (isset($rowsById[$id])) {
                $orderedRows[] = $rowsById[$id];
            }
        }

        return $orderedRows;
    }
}
