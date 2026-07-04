<?php

namespace App\Repository;

use App\Entity\SiteData;
use App\Service\CapacityUtilizationService;
use App\Service\SiteStatusDecisionService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SiteDataRepository extends ServiceEntityRepository
{
    private const SITE_SUFFIX_PATTERN = '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$';

    public function __construct(
        ManagerRegistry $registry,
        private readonly CapacityUtilizationService $capacityUtilizationService,
        private readonly SiteStatusDecisionService $siteStatusDecisionService,
    ) {
        parent::__construct($registry, SiteData::class);
    }

    public function findAllEnodeNames(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $lastDate = $conn->fetchOne('SELECT MAX(date_analyse) FROM analyse_resultat');
        if (!$lastDate) {
            return [];
        }

        return $conn->fetchAllAssociative(
            "SELECT DISTINCT UPPER(REGEXP_REPLACE(site, '" . self::SITE_SUFFIX_PATTERN . "', '', 'i')) AS enode_name
             FROM analyse_resultat
             WHERE date_analyse = :date
             ORDER BY enode_name ASC",
            ['date' => $lastDate]
        );
    }

    public function findEnodeNamesByUser($user): array
    {
        return $this->createQueryBuilder('s')
            ->select('DISTINCT s.enode_name')
            ->innerJoin('s.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('s.enode_name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findForExport(array $sitesList, string $dateRange, string $dateValue): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $suffixPattern = self::SITE_SUFFIX_PATTERN;
        $cleanSiteSql = "REGEXP_REPLACE(UPPER(ar.site), '$suffixPattern', '')";
        $sql = "
            SELECT
                ar.id,
                ar.site AS raw_site,
                UPPER(REGEXP_REPLACE(ar.site, '$suffixPattern', '', 'i')) AS enode_name,
                ar.classification,
                ar.type_trans AS type_liaison,
                ar.site_fdd,
                ar.site_tdd,
                ar.max_trafic,
                ar.seuil_critique,
                ar.nombre_occurrences,
                COALESCE(ar.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                COALESCE(ar.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                ar.capacite_mbps,
                COALESCE(NULLIF(ar.duree_jours, 0), 7) AS duree_jours,
                CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) AS seuil_observation,
                CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) AS seuil_critique_dynamique,
                CASE
                    WHEN COALESCE(ar.nombre_occurrences_tdd, 0) >= CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0)
                     AND COALESCE(ar.nombre_occurrences_fdd, 0) >= CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'CRITIQUE (TDD/FDD)'
                    WHEN COALESCE(ar.nombre_occurrences_tdd, 0) >= CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'CRITIQUE (TDD)'
                    WHEN COALESCE(ar.nombre_occurrences_fdd, 0) >= CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'CRITIQUE (FDD)'
                    WHEN ar.nombre_occurrences >= CEIL(50.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'CRITIQUE'
                    WHEN COALESCE(ar.nombre_occurrences_tdd, 0) >= CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0)
                     AND COALESCE(ar.nombre_occurrences_fdd, 0) >= CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'SOUS_OBSERVATION (TDD/FDD)'
                    WHEN COALESCE(ar.nombre_occurrences_tdd, 0) >= CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'SOUS_OBSERVATION (TDD)'
                    WHEN COALESCE(ar.nombre_occurrences_fdd, 0) >= CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'SOUS_OBSERVATION (FDD)'
                    WHEN ar.nombre_occurrences >= CEIL(20.0 * COALESCE(NULLIF(ar.duree_jours, 0), 7) / 7.0) THEN 'SOUS_OBSERVATION'
                    ELSE 'OK'
                END AS statut,
                ar.date_analyse AS updated_at,
                UPPER(REGEXP_REPLACE(ar.site, '$suffixPattern', '', 'i')) AS site_export,
                COALESCE(se.etat, 'OK') AS etat
            FROM analyse_resultat ar
            LEFT JOIN site_etat se
                ON UPPER(se.site) = REGEXP_REPLACE(UPPER(ar.site), '$suffixPattern', '')
        ";
        $conditions = [];
        $params = [];
        
        if (!empty($sitesList)) {
            $normalizedSites = array_map(
                static fn (string $site): string => preg_replace('/' . self::SITE_SUFFIX_PATTERN . '/i', '', strtoupper(trim($site))) ?: strtoupper(trim($site)),
                array_values($sitesList)
            );
            $placeholders = implode(', ', array_fill(0, count($normalizedSites), '?'));
            $conditions[] = "(UPPER(ar.site) IN ($placeholders) OR $cleanSiteSql IN ($placeholders))";
            $params = array_merge($params, $normalizedSites, $normalizedSites);
        }
        
        if ($dateRange !== 'all' && !empty($dateValue)) {
            $date = new \DateTime($dateValue);
            $year = $date->format('Y');
            $month = $date->format('m');
            $day = $date->format('d');
            
            switch ($dateRange) {
                case 'day':
                    $conditions[] = 'EXTRACT(YEAR FROM ar.date_analyse) = ? AND EXTRACT(MONTH FROM ar.date_analyse) = ? AND EXTRACT(DAY FROM ar.date_analyse) = ?';
                    $params[] = $year;
                    $params[] = $month;
                    $params[] = $day;
                    break;
                case 'week':
                    $conditions[] = 'EXTRACT(YEAR FROM ar.date_analyse) = ? AND EXTRACT(WEEK FROM ar.date_analyse) = ?';
                    $params[] = $year;
                    $params[] = $date->format('W');
                    break;
                case 'month':
                    $conditions[] = 'EXTRACT(YEAR FROM ar.date_analyse) = ? AND EXTRACT(MONTH FROM ar.date_analyse) = ?';
                    $params[] = $year;
                    $params[] = $month;
                    break;
            }
        }
        
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY ar.date_analyse DESC, ar.site ASC';
        
        $stmt = $conn->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();

        return $this->enrichExportCapacities($rows);
    }

    private function enrichExportCapacities(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $conn = $this->getEntityManager()->getConnection();
        $portMap = $this->buildPortSideMap($conn->fetchAllAssociative(
            'SELECT site, port_no, trafic
             FROM (
                SELECT site, port_no, trafic, date_import,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, :suffixPattern, \'\', \'i\'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
             ) latest_ports
             WHERE date_import = latest_site_date AND row_num = 1
             ORDER BY UPPER(site), port_no',
            ['suffixPattern' => self::SITE_SUFFIX_PATTERN]
        ));
        $capacityMap = $this->buildImportedCapacityMap($conn->fetchAllAssociative(
            'SELECT site, type_trans, capacite_mbps FROM capacite_site WHERE capacite_mbps > 0 ORDER BY date_mise_ajour DESC, id DESC'
        ));

        foreach ($rows as &$row) {
            $type = $this->normalizeTransmissionType((string) ($row['type_liaison'] ?? ''));
            $classification = strtoupper((string) ($row['classification'] ?? ''));
            $rawSite = (string) ($row['raw_site'] ?? $row['enode_name'] ?? '');
            $siteTdd = $row['site_tdd'] ?? null;
            $siteFdd = $row['site_fdd'] ?? null;
            $existingCapacity = (float) ($row['capacite_mbps'] ?? 0);

            $tddCapacity = 0.0;
            $fddCapacity = 0.0;
            $mainCapacity = $this->findImportedCapacity($capacityMap, $rawSite, $type);
            $importedTddCapacity = $this->findImportedCapacity($capacityMap, $siteTdd, $type);
            $importedFddCapacity = $this->findImportedCapacity($capacityMap, $siteFdd, $type);
            $hasImportedCapacity = ($mainCapacity + $importedTddCapacity + $importedFddCapacity) > 0;

            if ($hasImportedCapacity) {
                $tddCapacity = $importedTddCapacity;
                $fddCapacity = $importedFddCapacity;

                if (($tddCapacity + $fddCapacity) <= 0) {
                    if ($type === 'FH' || in_array($classification, ['FDD', 'ONLY_FDD', '-'], true)) {
                        $this->assignCapacityByPort($mainCapacity, $this->findPortSides($portMap, $rawSite), 'fdd', $tddCapacity, $fddCapacity);
                    } else {
                        $this->assignCapacityByPort($mainCapacity, $this->findPortSides($portMap, $rawSite), 'tdd', $tddCapacity, $fddCapacity);
                    }
                }
            } else {
                $sitePorts = $this->findPortSides($portMap, $rawSite);
                $tddPorts = $this->findPortSides($portMap, $siteTdd);
                $fddPorts = $this->findPortSides($portMap, $siteFdd);

                if ($type === 'FO') {
                    $tddCapacity = max($sitePorts['tdd'], $tddPorts['tdd'], $fddPorts['tdd']);
                    $fddCapacity = max($sitePorts['fdd'], $tddPorts['fdd'], $fddPorts['fdd']);
                } else {
                    if (($tddCapacity + $fddCapacity) <= 0) {
                        $fallbackSide = in_array($classification, ['FDD', 'ONLY_FDD', '-'], true) ? 'fdd' : null;
                        $this->assignCapacityByPort($mainCapacity, $sitePorts, $fallbackSide, $tddCapacity, $fddCapacity);
                    }
                }

                if (($tddCapacity + $fddCapacity) <= 0 && $existingCapacity > 0) {
                    $fallbackSide = in_array($classification, ['FDD', 'ONLY_FDD', '-'], true) ? 'fdd' : 'tdd';
                    $this->assignCapacityByPort($existingCapacity, $sitePorts, $fallbackSide, $tddCapacity, $fddCapacity);
                }
            }

            $row['capacite_tdd_mbps'] = $tddCapacity;
            $row['capacite_fdd_mbps'] = $fddCapacity;
            $row['capacite_mbps'] = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $tddCapacity,
                $fddCapacity,
                $tddCapacity + $fddCapacity > 0 ? $tddCapacity + $fddCapacity : $existingCapacity
            );

            $maxTrafic = (float) ($row['max_trafic'] ?? 0);
            $row['taux_utilisation'] = $this->capacityUtilizationService->computeUtilizationRate(
                $maxTrafic,
                $classification,
                $tddCapacity,
                $fddCapacity,
                (float) $row['capacite_mbps']
            );

            $decision = $this->siteStatusDecisionService->decide($row, (int) ($row['duree_jours'] ?? 7));
            $row['statut'] = $decision['label'];
        }

        return $rows;
    }

    private function buildPortSideMap(array $portRows): array
    {
        $map = [];

        foreach ($portRows as $row) {
            $site = strtoupper(trim((string) ($row['site'] ?? '')));
            $traffic = (float) ($row['trafic'] ?? 0);
            if ($site === '' || $traffic <= 0) {
                continue;
            }

            foreach ([$site, strtoupper($this->cleanSiteName($site))] as $key) {
                if ($key === '') {
                    continue;
                }

                $map[$key] ??= ['tdd' => 0.0, 'fdd' => 0.0];
                if ($this->isTddSiteName($site)) {
                    $map[$key]['tdd'] = max($map[$key]['tdd'], $traffic);
                } elseif ($this->isFddSiteName($site)) {
                    $map[$key]['fdd'] = max($map[$key]['fdd'], $traffic);
                } elseif ((int) ($row['port_no'] ?? -1) === 0) {
                    $map[$key]['tdd'] = max($map[$key]['tdd'], $traffic);
                } elseif ((int) ($row['port_no'] ?? -1) === 1) {
                    $map[$key]['fdd'] = max($map[$key]['fdd'], $traffic);
                }
            }
        }

        return $map;
    }

    private function isTddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(TC|TD|TDD)$/i', trim((string) $site));
    }

    private function isFddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(LM|LMB|LM2|FDD|BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$/i', trim((string) $site));
    }

    private function buildImportedCapacityMap(array $capacityRows): array
    {
        $map = [];

        foreach ($capacityRows as $row) {
            $site = strtoupper(trim((string) ($row['site'] ?? '')));
            $capacity = (float) ($row['capacite_mbps'] ?? 0);
            if ($site === '' || $capacity <= 0) {
                continue;
            }

            $type = $this->normalizeTransmissionType((string) ($row['type_trans'] ?? ''));
            foreach ([$site, strtoupper($this->cleanSiteName($site))] as $key) {
                if ($key === '') {
                    continue;
                }

                $map[$key] ??= [];
                if (!isset($map[$key][$type])) {
                    $map[$key][$type] = $capacity;
                }
                if (!isset($map[$key]['ANY'])) {
                    $map[$key]['ANY'] = $capacity;
                }
            }
        }

        return $map;
    }

    private function findPortSides(array $portMap, ?string $site): array
    {
        $site = trim((string) $site);
        if ($site === '') {
            return ['tdd' => 0.0, 'fdd' => 0.0];
        }

        foreach ([strtoupper($site), strtoupper($this->cleanSiteName($site))] as $key) {
            if ($key !== '' && isset($portMap[$key])) {
                return $portMap[$key];
            }
        }

        return ['tdd' => 0.0, 'fdd' => 0.0];
    }

    private function findImportedCapacity(array $capacityMap, ?string $site, string $type): float
    {
        $site = trim((string) $site);
        if ($site === '') {
            return 0.0;
        }

        foreach ([strtoupper($site), strtoupper($this->cleanSiteName($site))] as $key) {
            if ($key === '' || !isset($capacityMap[$key])) {
                continue;
            }

            return (float) ($capacityMap[$key][$type] ?? $capacityMap[$key]['ANY'] ?? 0);
        }

        return 0.0;
    }

    private function assignCapacityByPort(float $capacity, array $ports, ?string $fallbackSide, float &$tddCapacity, float &$fddCapacity): void
    {
        if ($capacity <= 0) {
            return;
        }

        $assigned = false;
        if (($ports['fdd'] ?? 0) > 0) {
            $fddCapacity = max($fddCapacity, $capacity);
            $assigned = true;
        }

        if (($ports['tdd'] ?? 0) > 0) {
            $tddCapacity = max($tddCapacity, $capacity);
            $assigned = true;
        }

        if ($assigned) {
            return;
        }

        if ($fallbackSide === 'fdd') {
            $fddCapacity = max($fddCapacity, $capacity);
        } elseif ($fallbackSide === 'tdd') {
            $tddCapacity = max($tddCapacity, $capacity);
        }
    }

    private function normalizeTransmissionType(string $type): string
    {
        $type = strtoupper(trim($type));
        $type = str_replace([' ', '-', '/', '+'], '_', $type);
        $type = preg_replace('/_+/', '_', $type);

        if ($type === 'FH' || str_starts_with($type, 'FH_')) {
            return 'FH';
        }

        if ($type === 'FO' || str_starts_with($type, 'FO_')) {
            return 'FO';
        }

        if ($type === 'BACKBONE' || str_starts_with($type, 'BH')) {
            return 'BACKBONE';
        }

        return $type ?: 'NON_DEFINI';
    }

    private function cleanSiteName(string $site): string
    {
        return preg_replace('/' . self::SITE_SUFFIX_PATTERN . '/i', '', trim($site)) ?: trim($site);
    }

    public function countCongestions(): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.max_trafic > s.seuil_critique')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
