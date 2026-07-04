<?php

namespace App\Service;

use App\Entity\AnalyseResultat;
use App\Repository\AnalyseResultatRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class StatisticsService
{
    private const CRITICAL_OCCURRENCES_PER_WEEK = 50;
    private const WATCH_OCCURRENCES_PER_WEEK = 20;
    private const RISK_CONGESTION_OCCURRENCES_LIMIT = 57;
    private const CONGESTION_RATE_THRESHOLD = 90.0;
    private const CAPACITY_CHECK_CODE = 'A_VERIFIER_CAPACITE';
    private const RISK_CONGESTION_CODE = 'RISQUE_DE_CONGESTION';

    private Connection $conn;
    private AnalyseResultatRepository $analyseRepo;
    private SiteStatusDecisionService $statusDecision;

    public function __construct(
        EntityManagerInterface $em,
        AnalyseResultatRepository $analyseRepo,
        SiteStatusDecisionService $statusDecision,
    ) {
        $this->conn = $em->getConnection();
        $this->analyseRepo = $analyseRepo;
        $this->statusDecision = $statusDecision;
    }

    private function getOccurrenceThreshold(int $durationDays, int $baseThreshold): int
    {
        $durationDays = max(1, $durationDays);

        return max(1, (int) ceil(($baseThreshold * $durationDays) / 7));
    }

    private function getCriticalOccurrencesSql(): string
    {
        return sprintf(
            'CEIL(%d.0 * COALESCE(NULLIF(duree_jours, 0), 7) / 7.0)',
            self::CRITICAL_OCCURRENCES_PER_WEEK
        );
    }

    private function getEffectiveCapacitySql(): string
    {
        return 'COALESCE(NULLIF(COALESCE(m.capacite_tdd_mbps, 0) + COALESCE(m.capacite_fdd_mbps, 0), 0), a.capacite_mbps)';
    }

    private function computeUtilizationRate(float $maxTrafic, ?float $capacity): ?float
    {
        $capacity = (float) ($capacity ?? 0);
        if ($maxTrafic <= 0 || $capacity <= 0) {
            return null;
        }

        return round(($maxTrafic / $capacity) * 100, 1);
    }

    private function resolveStatusByOccurrences(int $occurrences, int $durationDays): string
    {
        $criticalThreshold = $this->getOccurrenceThreshold($durationDays, 50);
        $watchThreshold = $this->getOccurrenceThreshold($durationDays, 20);

        if ($occurrences >= $criticalThreshold) {
            return 'critique';
        }

        if ($occurrences >= $watchThreshold) {
            return 'sous_observation';
        }

        return 'securise';
    }

    /**
     * Retourne la dernière date d'analyse présente en base.
     */
    public function getLastAnalyseDate(): ?string
    {
        return $this->conn->fetchOne(
            'SELECT DISTINCT date_analyse FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
        ) ?: null;
    }

    /**
     * Statistiques globales (tous types confondus).
     */
    public function getGlobalStats(): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return $this->emptyGlobalStats();
        }

        return [
            'total_sites'          => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE date_analyse = :date', ['date' => $lastDate]),
            'sites_congestionnes'  => $this->getSitesCongestionnesCount(),
            'sites_tf'             => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'TF', 'date' => $lastDate]),
            'sites_cotrans'        => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'COTRANS', 'date' => $lastDate]),
            'sites_nocotrans'      => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NO_COTRANS', 'date' => $lastDate]),
            'sites_only_fdd'       => (int)$this->conn->fetchOne("SELECT COUNT(*) FROM analyse_resultat WHERE classification IN ('ONLY_FDD', 'FDD') AND date_analyse = :date", ['date' => $lastDate]),
            'sites_nontdd'         => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NON_TDD', 'date' => $lastDate]),
            'total_occurrences'    => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE date_analyse = :date', ['date' => $lastDate]),
            'fo'                   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FO%', 'date' => $lastDate]),
            'fh'                   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FH%', 'date' => $lastDate]),
            'backbone'             => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
            'sans_type'            => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'NON_DEFINI', 'date' => $lastDate]),
            'fo_congestions'       => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FO%', 'date' => $lastDate]),
            'fh_congestions'       => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FH%', 'date' => $lastDate]),
            'backbone_congestions' => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
            'max_trafic_fo'        => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FO%', 'date' => $lastDate]),
            'max_trafic_fh'        => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date', ['type' => 'FH%', 'date' => $lastDate]),
            'max_trafic_backbone'  => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
        ];
    }

    private function emptyGlobalStats(): array
    {
        return [
            'total_sites' => 0, 'sites_tf' => 0, 'sites_cotrans' => 0,
            'sites_nocotrans' => 0, 'sites_only_fdd' => 0, 'sites_nontdd' => 0, 'total_occurrences' => 0,
            'sites_congestionnes' => 0,
            'fo' => 0, 'fh' => 0, 'backbone' => 0, 'sans_type' => 0,
            'fo_congestions' => 0, 'fh_congestions' => 0, 'backbone_congestions' => 0,
            'max_trafic_fo' => 0, 'max_trafic_fh' => 0, 'max_trafic_backbone' => 0,
        ];
    }

    /**
     * Statistiques pour un type spécifique (FO, FH) ou Backbone.
     */
    public function getStatsByType(string $type): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return $this->emptyTypeStats();
        }

        if ($type === 'BACKBONE') {
            $t = 'BH%';
            $sqlTotal = 'SELECT COUNT(*) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date';
            $sqlOcc = 'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date';
            $sqlMax = 'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date';
            $sqlCrit = 'SELECT COUNT(*) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern)
                        AND nombre_occurrences >= ' . $this->getCriticalOccurrencesSql() . '
                        AND date_analyse = :date';
        } else {
            $t = strtoupper($type);
            $sqlTotal = 'SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date';
            $sqlOcc = 'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date';
            $sqlMax = 'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type AND date_analyse = :date';
            $sqlCrit = 'SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) LIKE :type
                        AND nombre_occurrences >= ' . $this->getCriticalOccurrencesSql() . '
                        AND date_analyse = :date';
        }

        $params = ['date' => $lastDate];
        if ($type !== 'BACKBONE') {
            $params['type'] = match ($t) {
                'FO' => 'FO%',
                'FH' => 'FH%',
                default => $t,
            };
        } else {
            $params['exact'] = 'BACKBONE';
            $params['pattern'] = 'BH%';
        }

        return [
            'total'             => (int)$this->conn->fetchOne($sqlTotal, $params),
            'total_tf'          => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'TF', 'date' => $lastDate]),
            'total_cotrans'     => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'COTRANS', 'date' => $lastDate]),
            'total_nocotrans'   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NO_COTRANS', 'date' => $lastDate]),
            'total_only_fdd'    => (int)$this->conn->fetchOne("SELECT COUNT(*) FROM analyse_resultat WHERE classification IN ('ONLY_FDD', 'FDD') AND date_analyse = :date", ['date' => $lastDate]),
            'total_nontdd'      => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NON_TDD', 'date' => $lastDate]),
            'total_occ'         => (int)$this->conn->fetchOne($sqlOcc, $params),
            'max_trafic'        => (float)$this->conn->fetchOne($sqlMax, $params),
            'sites_critiques'   => (int)$this->conn->fetchOne($sqlCrit, $params),
        ];
    }

    private function emptyTypeStats(): array
    {
        return [
            'total' => 0, 'total_tf' => 0, 'total_cotrans' => 0,
            'total_nocotrans' => 0, 'total_only_fdd' => 0, 'total_nontdd' => 0,
            'total_occ' => 0, 'max_trafic' => 0, 'sites_critiques' => 0,
        ];
    }

    /**
     * Récupère les sites critiques selon la durée d'analyse avec limite.
     * Utilisé pour l'affichage dans la carte (10 ou 20 premiers).
     */
    public function getSitesCritiques(int $limit = 20): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) return [];

        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.*, 
                    m.capacite_tdd_mbps, m.capacite_fdd_mbps, m.classification as managed_classification, 
                    m.type_trans as managed_type_trans
             FROM analyse_resultat a
             LEFT JOIN managed_site m ON LOWER(m.name) = LOWER(a.site)
             WHERE a.nombre_occurrences >= ' . $this->getCriticalOccurrencesSql() . '
               AND a.date_analyse = :date
             ORDER BY a.nombre_occurrences DESC
             LIMIT :limit',
            ['date' => $lastDate, 'limit' => $limit]
        );

        $sites = [];
        foreach ($rows as $row) {
            $site = new \App\Entity\AnalyseResultat();
            $site->setSite($row['site']);
            
            // Priorité aux données ManagedSite (SuperUser)
            $classification = $row['managed_classification'] ?? $row['classification'];
            $typeTrans = $row['managed_type_trans'] ?? $row['type_trans'];
            
            $site->setClassification($classification);
            $site->setTypeTrans($typeTrans);
            $site->setMaxTrafic((float)$row['max_trafic']);
            $site->setSeuilCritique((float)$row['seuil_critique']);
            $site->setNombreOccurrences((int)$row['nombre_occurrences']);
            $site->setTotalMeasures((int)$row['total_measures']);
            $site->setDateAnalyse(new \DateTime($row['date_analyse']));
            $site->setDureeJours((int) ($row['duree_jours'] ?? 7));
            
            // Calcul de la capacité effective
            $capTdd = (float) ($row['capacite_tdd_mbps'] ?? 0);
            $capFdd = (float) ($row['capacite_fdd_mbps'] ?? 0);
            
            $effectiveCapacity = $capTdd + $capFdd > 0 ? $capTdd + $capFdd : (float) ($row['capacite_mbps'] ?? 0);
            // Si on a des données TDD/FDD précises, on peut être plus fin, mais ici on simplifie
            
            $site->setCapaciteMbps($effectiveCapacity);
            $site->setTauxUtilisation($this->computeUtilizationRate(
                (float) ($row['max_trafic'] ?? 0),
                $effectiveCapacity
            ));
            $sites[] = $site;
        }
        return $sites;
    }

    /**
     * Retourne le nombre TOTAL de sites critiques selon la durée d'analyse sans limite.
     * Utilisé pour le compteur dans les statistiques.
     */
    public function getTotalSitesCritiquesCount(): int
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }
        
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM analyse_resultat
             WHERE nombre_occurrences >= ' . $this->getCriticalOccurrencesSql() . '
               AND date_analyse = :date',
            ['date' => $lastDate]
        );
        
        return (int) $count;
    }

    public function getSitesCongestionnesCount(): int
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }

        // Charge toutes les lignes de la dernière analyse avec les capacités enrichies
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.type_trans, a.classification,
                    a.max_trafic,
                    COALESCE(a.max_trafic_tdd, 0) AS max_trafic_tdd,
                    COALESCE(a.max_trafic_fdd, 0) AS max_trafic_fdd,
                    a.capacite_mbps,
                    a.nombre_occurrences,
                    COALESCE(a.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                    COALESCE(a.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                    COALESCE(m.capacite_tdd_mbps, 0) AS capacite_tdd_mbps,
                    COALESCE(m.capacite_fdd_mbps, 0) AS capacite_fdd_mbps
             FROM analyse_resultat a
             LEFT JOIN managed_site m ON LOWER(m.name) = LOWER(a.site)
             WHERE a.date_analyse = :date',
            ['date' => $lastDate]
        );

        $count = 0;
        foreach ($rows as $row) {
            $capTdd      = (float) $row['capacite_tdd_mbps'];
            $capFdd      = (float) $row['capacite_fdd_mbps'];
            $effectiveCap = ($capTdd + $capFdd > 0)
                ? $capTdd + $capFdd
                : (float) ($row['capacite_mbps'] ?? 0);

            $maxTrafic    = (float) ($row['max_trafic'] ?? 0);
            $maxTraficTdd = (float) $row['max_trafic_tdd'];
            $maxTraficFdd = (float) $row['max_trafic_fdd'];

            $siteData = [
                'type_trans'             => $row['type_trans'],
                'classification'         => $row['classification'],
                'max_trafic'             => $maxTrafic,
                'capacite_mbps'          => $effectiveCap,
                'capacite_tdd_mbps'      => $capTdd,
                'capacite_fdd_mbps'      => $capFdd,
                'nombre_occurrences'     => (int) ($row['nombre_occurrences'] ?? 0),
                'nombre_occurrences_tdd' => (int) $row['nombre_occurrences_tdd'],
                'nombre_occurrences_fdd' => (int) $row['nombre_occurrences_fdd'],
                'taux_utilisation'       => $effectiveCap > 0
                    ? round($maxTrafic / $effectiveCap * 100, 2) : null,
                'taux_utilisation_tdd'   => $capTdd > 0 && $maxTraficTdd > 0
                    ? round($maxTraficTdd / $capTdd * 100, 2) : null,
                'taux_utilisation_fdd'   => $capFdd > 0 && $maxTraficFdd > 0
                    ? round($maxTraficFdd / $capFdd * 100, 2) : null,
            ];

            $etat = $this->statusDecision->decideEtat($siteData);
            if (str_starts_with($etat, 'CONGESTION')) {
                ++$count;
            }
        }

        return $count;
    }

    public function getSiteHealthStats(): array
    {
        if (!$this->tableExists('site_etat')) {
            $congestionnes = $this->getSitesCongestionnesCount();

            return [
                'total' => $this->getLatestAnalyseSitesCount(),
                'congestionnes' => $congestionnes,
                'brides' => 0,
                'ok' => max(0, $this->getLatestAnalyseSitesCount() - $congestionnes),
                'avg_taux' => 0.0,
            ];
        }

        $row = $this->conn->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN UPPER(etat) IN ('CONGESTIONNE', 'CONGESTION') THEN 1 ELSE 0 END) AS congestionnes,
                SUM(CASE WHEN taux_utilisation < " . self::CONGESTION_RATE_THRESHOLD . " AND UPPER(etat) IN ('BRIDE', 'BRIDAGE') THEN 1 ELSE 0 END) AS brides,
                SUM(CASE WHEN UPPER(etat) = 'OK' THEN 1 ELSE 0 END) AS ok,
                COALESCE(AVG(NULLIF(taux_utilisation, 0)), 0) AS avg_taux
             FROM site_etat"
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'congestionnes' => (int) ($row['congestionnes'] ?? 0),
            'brides' => (int) ($row['brides'] ?? 0),
            'ok' => (int) ($row['ok'] ?? 0),
            'avg_taux' => round((float) ($row['avg_taux'] ?? 0), 1),
        ];
    }

    public function getSiteHealthByGovernorate(): array
    {
        if (!$this->tableExists('site_etat')) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            "SELECT site, etat, taux_utilisation
             FROM site_etat
             WHERE UPPER(etat) IN ('CONGESTIONNE', 'CONGESTION', 'BRIDE', 'BRIDAGE')
             ORDER BY taux_utilisation DESC"
        );

        $stats = [];
        foreach ($rows as $row) {
            $governorate = $this->getGovernorateFromSite((string) $row['site']);
            if (!isset($stats[$governorate])) {
                $stats[$governorate] = [
                    'gouvernorat' => $governorate,
                    'total' => 0,
                    'congestionnes' => 0,
                    'brides' => 0,
                    'max_taux' => 0.0,
                ];
            }

            $etat = strtoupper((string) $row['etat']);
            $stats[$governorate]['total']++;
            $stats[$governorate]['max_taux'] = max($stats[$governorate]['max_taux'], (float) $row['taux_utilisation']);

            if (in_array($etat, ['CONGESTIONNE', 'CONGESTION'], true)) {
                $stats[$governorate]['congestionnes']++;
            } elseif (in_array($etat, ['BRIDE', 'BRIDAGE'], true)) {
                $stats[$governorate]['brides']++;
            }
        }

        usort($stats, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

        return array_slice($stats, 0, 8);
    }

    public function getTopSiteHealthRisks(int $limit = 8): array
    {
        if (!$this->tableExists('site_etat')) {
            return $this->getTopHealthRisksFromAnalyse($limit);
        }

        return $this->conn->fetchAllAssociative(
            "SELECT
                se.site,
                se.etat,
                se.trafic_j,
                se.capacite_mbps,
                se.taux_utilisation,
                se.variation_j1,
                se.variation_j7,
                latest.type_trans,
                latest.classification,
                latest.nombre_occurrences
             FROM site_etat se
             LEFT JOIN (
                SELECT DISTINCT ON (site)
                    site, type_trans, classification, nombre_occurrences, date_analyse, id
                FROM analyse_resultat
                ORDER BY site, date_analyse DESC, id DESC
             ) latest ON latest.site = se.site
             WHERE UPPER(se.etat) IN ('CONGESTIONNE', 'CONGESTION', 'RISQUE_DE_CONGESTION', 'A_VERIFIER_CAPACITE', 'CAPACITE_A_VERIFIER', 'BRIDE', 'BRIDAGE')
             ORDER BY
                CASE
                    WHEN UPPER(se.etat) IN ('CONGESTIONNE', 'CONGESTION') THEN 0
                    WHEN UPPER(se.etat) = 'RISQUE_DE_CONGESTION' THEN 1
                    WHEN UPPER(se.etat) IN ('A_VERIFIER_CAPACITE', 'CAPACITE_A_VERIFIER') THEN 2
                    ELSE 2
                END,
                se.taux_utilisation DESC,
                ABS(se.variation_j1) ASC
             LIMIT :limit",
            ['limit' => $limit]
        );
    }

    private function getLatestAnalyseSitesCount(): int
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }

        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM analyse_resultat WHERE date_analyse = :date',
            ['date' => $lastDate]
        );
    }

    private function getTopHealthRisksFromAnalyse(int $limit): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return [];
        }
        $riskCongestionOccurrencesSql = (string) self::RISK_CONGESTION_OCCURRENCES_LIMIT;
        $criticalOccurrencesSql = (string) self::CRITICAL_OCCURRENCES_PER_WEEK;
        $effectiveCapacitySql = $this->getEffectiveCapacitySql();

        return $this->conn->fetchAllAssociative(
            "SELECT
                a.site,
                COALESCE(NULLIF(a.duree_jours, 0), 7) AS duree_jours,
                CASE
                    WHEN $effectiveCapacitySql IS NOT NULL
                     AND $effectiveCapacitySql > 0
                     AND (a.max_trafic / $effectiveCapacitySql) * 100 >= " . self::CONGESTION_RATE_THRESHOLD . "
                     AND a.nombre_occurrences >= " . $riskCongestionOccurrencesSql . " THEN 'CONGESTION'
                    WHEN $effectiveCapacitySql IS NOT NULL
                     AND $effectiveCapacitySql > 0
                     AND (a.max_trafic / $effectiveCapacitySql) * 100 >= " . self::CONGESTION_RATE_THRESHOLD . "
                     AND a.nombre_occurrences < " . self::RISK_CONGESTION_OCCURRENCES_LIMIT . " THEN '" . self::RISK_CONGESTION_CODE . "'
                    WHEN $effectiveCapacitySql IS NOT NULL
                     AND $effectiveCapacitySql > 0
                     AND (a.max_trafic / $effectiveCapacitySql) * 100 < " . self::CONGESTION_RATE_THRESHOLD . "
                     AND a.nombre_occurrences >= " . $criticalOccurrencesSql . " THEN 'BRIDAGE'
                    ELSE 'OK'
                END AS etat,
                a.max_trafic AS trafic_j,
                $effectiveCapacitySql as capacite_mbps,
                CASE
                    WHEN COALESCE(m.capacite_tdd_mbps, 0) + COALESCE(m.capacite_fdd_mbps, 0) > 0 THEN ROUND((a.max_trafic / (m.capacite_tdd_mbps + m.capacite_fdd_mbps)) * 100, 2)
                    WHEN a.capacite_mbps IS NOT NULL AND a.capacite_mbps > 0 THEN ROUND((a.max_trafic / a.capacite_mbps) * 100, 2)
                    ELSE 0
                END AS taux_utilisation,
                0 AS variation_j1,
                0 AS variation_j7,
                COALESCE(m.type_trans, a.type_trans) as type_trans,
                COALESCE(m.classification, a.classification) as classification,
                a.nombre_occurrences
             FROM analyse_resultat a
             LEFT JOIN managed_site m ON LOWER(m.name) = LOWER(a.site)
             WHERE a.date_analyse = :date
               AND (
                    ($effectiveCapacitySql IS NOT NULL
                     AND $effectiveCapacitySql > 0
                     AND (
                        ((a.max_trafic / $effectiveCapacitySql) * 100 >= " . self::CONGESTION_RATE_THRESHOLD . "
                            AND (a.nombre_occurrences >= " . $riskCongestionOccurrencesSql . "
                                OR a.nombre_occurrences < " . self::RISK_CONGESTION_OCCURRENCES_LIMIT . "))
                        OR ((a.max_trafic / $effectiveCapacitySql) * 100 < " . self::CONGESTION_RATE_THRESHOLD . "
                            AND a.nombre_occurrences >= " . $criticalOccurrencesSql . ")
                     ))
               )
             ORDER BY a.nombre_occurrences DESC
             LIMIT :limit",
            ['date' => $lastDate, 'limit' => $limit]
        );
    }

    /**
     * Retourne tous les sites avec coordonnées GPS (pour la carte).
     */
    public function getSitesForGps(\App\Service\GeocodingService $geocodingService): array
    {
        $results = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT ON (site)
                id, site, classification, type_trans, max_trafic, seuil_critique,
                nombre_occurrences, latitude, longitude, date_analyse, COALESCE(NULLIF(duree_jours, 0), 7) AS duree_jours
             FROM analyse_resultat
             ORDER BY site, date_analyse DESC, id DESC'
        );

        $knownCoords = $this->getLatestKnownGpsBySite();
        $etatBySite = $this->getEtatBySite();

        $sites = [];
        foreach ($results as $row) {
            $occurrences = (int)$row['nombre_occurrences'];
            $dureeJours = (int) ($row['duree_jours'] ?? 7);
            $etat = strtoupper((string) ($etatBySite[$row['site']] ?? ''));
            $statut = $this->resolveStatusByOccurrences($occurrences, $dureeJours);
            if ($statut === 'securise' && in_array($etat, ['CONGESTIONNE', 'CONGESTION', 'BRIDE', 'BRIDAGE'], true)) {
                $statut = 'sous_observation';
            }
            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;

            if ((!$latitude || !$longitude || $latitude == 0 || $longitude == 0) && isset($knownCoords[$row['site']])) {
                $latitude = $knownCoords[$row['site']]['latitude'];
                $longitude = $knownCoords[$row['site']]['longitude'];
            }

            if (!$latitude || !$longitude || $latitude == 0 || $longitude == 0) {
                $coords = $geocodingService->getCoordinatesFromSiteName($row['site']);
                $latitude = $coords['latitude'];
                $longitude = $coords['longitude'];
            }

            $sites[] = [
                'id' => $row['id'],
                'nom' => $row['site'],
                'code' => $row['site'],
                'classification' => $row['classification'],
                'typeTrans' => $row['type_trans'] ?? 'NON_DEFINI',
                'maxTrafic' => (float)$row['max_trafic'],
                'seuilCritique' => (float)$row['seuil_critique'],
                'nbOccurrences' => $occurrences,
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'statut' => $statut,
                'estimation2026' => $this->calculateEstimation($occurrences, (float)$row['max_trafic'], $dureeJours),
            ];
        }
        return $sites;
    }

    private function getLatestKnownGpsBySite(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT ON (site) site, latitude, longitude
             FROM analyse_resultat
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
               AND latitude <> 0 AND longitude <> 0
             ORDER BY site, date_analyse DESC, id DESC'
        );

        $coords = [];
        foreach ($rows as $row) {
            $coords[$row['site']] = [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }

        return $coords;
    }

    private function getEtatBySite(): array
    {
        if (!$this->tableExists('site_etat')) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative('SELECT site, etat FROM site_etat');
        $etats = [];
        foreach ($rows as $row) {
            $etats[$row['site']] = $row['etat'];
        }

        return $etats;
    }

    private function getGovernorateFromSite(string $site): string
    {
        $prefix = strtoupper(substr(trim($site), 0, 3));

        $prefixMap = [
            'TUN' => 'Tunis',
            'ARI' => 'Ariana',
            'BEN' => 'Ben Arous',
            'MAN' => 'Manouba',
            'NAB' => 'Nabeul',
            'ZAG' => 'Zaghouan',
            'BIZ' => 'Bizerte',
            'BEJ' => 'Beja',
            'JEN' => 'Jendouba',
            'KEF' => 'Le Kef',
            'SIL' => 'Siliana',
            'SOU' => 'Sousse',
            'MON' => 'Monastir',
            'MAH' => 'Mahdia',
            'SFA' => 'Sfax',
            'KAI' => 'Kairouan',
            'KAS' => 'Kasserine',
            'SIB' => 'Sidi Bouzid',
            'GAB' => 'Gabes',
            'MED' => 'Medenine',
            'TAT' => 'Tataouine',
            'GAF' => 'Gafsa',
            'TOZ' => 'Tozeur',
            'KEB' => 'Kebili',
        ];

        return $prefixMap[$prefix] ?? 'Autres';
    }

    private function tableExists(string $tableName): bool
    {
        return (bool) $this->conn->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_name = :table
            )",
            ['table' => $tableName]
        );
    }

    private function calculateEstimation(int $occurrences, float $maxTrafic, int $durationDays): int
    {
        if ($occurrences >= $this->getOccurrenceThreshold($durationDays, 50)) return 25;
        if ($occurrences >= $this->getOccurrenceThreshold($durationDays, 20)) return 15;
        if ($occurrences > 0) return 8;
        if ($maxTrafic > 500) return 10;
        return 3;
    }
}
