<?php

namespace App\Controller;

use App\Service\CapacityUtilizationService;
use App\Service\SiteStatusDecisionService;
use App\Service\StatisticsService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class TransmissionController extends AbstractController
{
    // ⚡ CONSTANTES DE PERFORMANCE
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 200;
    private const MANAGED_SITES_LIMIT = 50;

    private bool $hasDropCongColumns = false;
    private array $tableExistsCache = [];
    private ?array $portCapacitiesCache = null;
    private ?array $foCapacitiesCache = null;
    private ?array $fhCapacitiesCache = null;
    private ?array $backboneCapacitiesCache = null;

    public function __construct(
        private readonly StatisticsService $statsService,
        private readonly CapacityUtilizationService $capacityUtilizationService,
        private readonly SiteStatusDecisionService $siteStatusDecisionService,
    ) {
    }

    private function cleanSiteName(string $site): string
    {
        $cleaned = trim($site);

        do {
            $previous = $cleaned;
            $cleaned = preg_replace('/_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$/i', '', $cleaned);
            $cleaned = preg_replace('/_(BH|BO|TR|OO|OR|TT)(?:_[A-Z0-9]+)*$/i', '', $cleaned);
            $cleaned = trim((string) $cleaned);
        } while ($cleaned !== $previous);

        return $cleaned;
    }

    private function formatDecimalNoRounding(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '0';
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return $value;
        }

        if (stripos($value, 'e') !== false) {
            $value = sprintf('%.14F', (float) $value);
        }

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return str_replace('.', ',', $value);
    }

    private function formatTauxUtilisationPourcent(?float $taux): string
    {
        if ($taux === null) {
            return '';
        }

        return number_format((float) $taux, 2, ',', ' ') . '%';
    }

    private function calculerDureeEnJours(string $dateDebut, string $dateFin): int
    {
        $debut = new \DateTime($dateDebut);
        $fin = new \DateTime($dateFin);
        $interval = $debut->diff($fin);
        return $interval->days + 1;
    }

    private function findClosestKey(?array $statsJson, string $targetKey): ?string
    {
        if (empty($statsJson) || !is_array($statsJson)) {
            return null;
        }
        
        $target = (int)$targetKey;
        $closest = null;
        $minDiff = PHP_INT_MAX;
        
        foreach (array_keys($statsJson) as $key) {
            $keyInt = (int)$key;
            $diff = abs($keyInt - $target);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $key;
            }
        }
        
        return $closest;
    }

    private function extractStatsForDuree(?array $statsJson, string $dureeKey): array
    {
        if (empty($statsJson) || !is_array($statsJson)) {
            return [
                'max_trafic' => null,
                'nombre_occurrences' => null,
                'seuil_critique' => null,
                'date_max' => null,
                'max_fdd' => null,
                'max_tdd' => null,
                'nombre_occurrences_tdd' => null,
                'nombre_occurrences_fdd' => null
            ];
        }
        
        if (isset($statsJson[$dureeKey])) {
            return [
                'max_trafic' => (float) ($statsJson[$dureeKey]['max_trafic'] ?? 0),
                'nombre_occurrences' => (int) ($statsJson[$dureeKey]['occurrences'] ?? 0),
                'seuil_critique' => (float) ($statsJson[$dureeKey]['seuil_critique'] ?? 0),
                'date_max' => $statsJson[$dureeKey]['date_max'] ?? null,
                'max_fdd' => (float) ($statsJson[$dureeKey]['max_fdd'] ?? 0),
                'max_tdd' => (float) ($statsJson[$dureeKey]['max_tdd'] ?? 0),
                'nombre_occurrences_tdd' => (int) ($statsJson[$dureeKey]['occurrences_tdd'] ?? 0),
                'nombre_occurrences_fdd' => (int) ($statsJson[$dureeKey]['occurrences_fdd'] ?? 0)
            ];
        }
        
        $closestKey = $this->findClosestKey($statsJson, $dureeKey);
        if ($closestKey && isset($statsJson[$closestKey])) {
            return [
                'max_trafic' => (float) ($statsJson[$closestKey]['max_trafic'] ?? 0),
                'nombre_occurrences' => (int) ($statsJson[$closestKey]['occurrences'] ?? 0),
                'seuil_critique' => (float) ($statsJson[$closestKey]['seuil_critique'] ?? 0),
                'date_max' => $statsJson[$closestKey]['date_max'] ?? null,
                'max_fdd' => (float) ($statsJson[$closestKey]['max_fdd'] ?? 0),
                'max_tdd' => (float) ($statsJson[$closestKey]['max_tdd'] ?? 0),
                'nombre_occurrences_tdd' => (int) ($statsJson[$closestKey]['occurrences_tdd'] ?? 0),
                'nombre_occurrences_fdd' => (int) ($statsJson[$closestKey]['occurrences_fdd'] ?? 0)
            ];
        }
        
        return [
            'max_trafic' => null,
            'nombre_occurrences' => null,
            'seuil_critique' => null,
            'date_max' => null,
            'max_fdd' => null,
            'max_tdd' => null,
            'nombre_occurrences_tdd' => null,
            'nombre_occurrences_fdd' => null
        ];
    }

    private function buildSelectQuery(EntityManagerInterface $em, string $baseQuery, array $params, string $search = '', string $classFilter = ''): array
    {
        $conn = $em->getConnection();
        
        $sql = $baseQuery;
        
        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }
        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }
        
        return $conn->fetchAllAssociative($sql, $params);
    }

    private function appendManagedSites(EntityManagerInterface $em, array $results, string $search = '', string $classFilter = '', ?string $typeFilter = null): array
    {
        if (!$this->tableExists($em, 'managed_site')) {
            return $results;
        }

        $seen = [];
        foreach ($results as $row) {
            $name = trim((string) ($row['site'] ?? ''));
            if ($name === '') {
                continue;
            }

            $seen[mb_strtolower($name)] = true;
            $seen[mb_strtolower($this->cleanSiteName($name))] = true;
        }

        // ⚡ LIMITER LE NOMBRE DE MANAGED SITES
        $managedSites = $em->getConnection()->fetchAllAssociative(
            'SELECT id, name, status, max_trafic, max_tdd, max_fdd, capacite_tdd_mbps,
                    capacite_fdd_mbps, taux_utilisation, nombre_occurrences,
                    nombre_occurrences_tdd, nombre_occurrences_fdd, decision_statut,
                    etat, date_max, type_trans, classification, latitude, longitude,
                    description, created_at, updated_at
             FROM managed_site
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::MANAGED_SITES_LIMIT
        );

        $search = trim($search);
        $searchNeedle = mb_strtolower($this->cleanSiteName($search));
        $classFilter = $this->normalizeClassificationFilter($classFilter);
        $typeFilter = $typeFilter ? $this->normalizeTransmissionType($typeFilter) : null;

        foreach ($managedSites as $site) {
            $name = trim((string) ($site['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $nameKey = mb_strtolower($name);
            $cleanKey = mb_strtolower($this->cleanSiteName($name));
            if (isset($seen[$nameKey]) || isset($seen[$cleanKey])) {
                continue;
            }

            $classification = $this->normalizeClassification($site['classification'] ?? '');
            $type = $this->normalizeTransmissionType($site['type_trans'] ?? '');

            if ($searchNeedle !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $name,
                    $this->cleanSiteName($name),
                    $site['type_trans'] ?? '',
                    $site['classification'] ?? '',
                    $site['description'] ?? '',
                ]));

                if (!str_contains($haystack, $searchNeedle)) {
                    continue;
                }
            }

            if ($classFilter !== '') {
                if ($classFilter === 'ONLY_FDD') {
                    if (!in_array($classification, ['ONLY_FDD', 'FDD', '-'], true)) {
                        continue;
                    }
                } elseif ($classification !== $classFilter) {
                    continue;
                }
            }

            if ($typeFilter !== null) {
                if ($typeFilter === 'FO' || $typeFilter === 'FH') {
                    if ($type !== $typeFilter) {
                        continue;
                    }
                } elseif ($typeFilter === 'BACKBONE' && $type !== 'BACKBONE') {
                    continue;
                }
            }

            $capaciteTdd = (float) ($site['capacite_tdd_mbps'] ?? 0);
            $capaciteFdd = (float) ($site['capacite_fdd_mbps'] ?? 0);
            $maxTrafic = (float) ($site['max_trafic'] ?? 0);
            $effectiveCapacity = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $capaciteTdd,
                $capaciteFdd,
                $capaciteTdd + $capaciteFdd
            );

            $managedRow = [
                'id' => 'managed_' . $site['id'],
                'site' => $name,
                'site_clean' => $this->cleanSiteName($name),
                'classification' => $classification,
                'classification_display' => $classification === 'ONLY_FDD' ? 'ONLY FDD' : $classification,
                'type_trans' => $site['type_trans'] ?: null,
                'date_analyse' => $site['updated_at'] ?? $site['created_at'] ?? null,
                'latitude' => $site['latitude'] !== null ? (float) $site['latitude'] : null,
                'longitude' => $site['longitude'] !== null ? (float) $site['longitude'] : null,
                'site_fdd' => null,
                'site_tdd' => null,
                'max_trafic' => $maxTrafic,
                'nombre_occurrences' => (int) ($site['nombre_occurrences'] ?? 0),
                'seuil_critique' => 0,
                'date_max' => $site['date_max'] ?? null,
                'max_trafic_fdd' => (float) ($site['max_fdd'] ?? 0),
                'max_trafic_tdd' => (float) ($site['max_tdd'] ?? 0),
                'max_fdd' => (float) ($site['max_fdd'] ?? 0),
                'max_tdd' => (float) ($site['max_tdd'] ?? 0),
                'nombre_occurrences_tdd' => (int) ($site['nombre_occurrences_tdd'] ?? 0),
                'nombre_occurrences_fdd' => (int) ($site['nombre_occurrences_fdd'] ?? 0),
                'dropcong_tdd' => 0,
                'dropcong_fdd' => 0,
                'dropcong_tf' => 0,
                'capacite_tdd_mbps' => $capaciteTdd,
                'capacite_fdd_mbps' => $capaciteFdd,
                'capacite_mbps' => $effectiveCapacity,
                'duree_jours' => 7,
                'stats_json' => null,
                'dropcong_kpi' => 'non',
                'statut' => $site['decision_statut'] ?: 'OK',
                'statut_label' => $site['decision_statut'] ?: 'OK',
                'etat' => $site['etat'] ?: 'OK',
                'source' => 'managed_site',
            ];
            $this->applyUtilizationRates($managedRow);
            $results[] = $managedRow;

            $seen[$nameKey] = true;
            $seen[$cleanKey] = true;
        }

        return $results;
    }

    private function tableExists(EntityManagerInterface $em, string $tableName): bool
    {
        if (isset($this->tableExistsCache[$tableName])) {
            return $this->tableExistsCache[$tableName];
        }

        $result = (bool) $em->getConnection()->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_name = :table
            )",
            ['table' => $tableName]
        );
        
        $this->tableExistsCache[$tableName] = $result;
        return $result;
    }

    private function tableColumnExists(EntityManagerInterface $em, string $tableName, string $columnName): bool
    {
        return (bool) $em->getConnection()->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table
                  AND column_name = :column
            )",
            ['table' => $tableName, 'column' => $columnName]
        );
    }

    private function buildDropCongSelectSql(EntityManagerInterface $em): string
    {
        // ⚡ CACHER LE RÉSULTAT
        if ($this->hasDropCongColumns) {
            return 'COALESCE(a.dropcong_tdd, 0) AS dropcong_tdd,
                    COALESCE(a.dropcong_fdd, 0) AS dropcong_fdd,
                    COALESCE(a.dropcong_tf, 0) AS dropcong_tf';
        }

        if (
            !$this->tableColumnExists($em, 'analyse_resultat', 'dropcong_tdd')
            || !$this->tableColumnExists($em, 'analyse_resultat', 'dropcong_fdd')
            || !$this->tableColumnExists($em, 'analyse_resultat', 'dropcong_tf')
        ) {
            $this->hasDropCongColumns = false;
            return '0 AS dropcong_tdd, 0 AS dropcong_fdd, 0 AS dropcong_tf';
        }

        $this->hasDropCongColumns = true;
        return 'COALESCE(a.dropcong_tdd, 0) AS dropcong_tdd,
                COALESCE(a.dropcong_fdd, 0) AS dropcong_fdd,
                COALESCE(a.dropcong_tf, 0) AS dropcong_tf';
    }

    private function normalizeTransmissionType(?string $type): string
    {
        $type = strtoupper(trim((string) $type));
        $type = str_replace([' ', '-', '/', '+'], '_', $type);

        if ($type === '' || in_array($type, ['NON_DEFINI', 'NONE', 'N_A', 'NA'], true)) {
            return 'NON_DEFINI';
        }

        if ($type === 'FH' || str_starts_with($type, 'FH_')) {
            return 'FH';
        }

        if ($type === 'FO' || str_starts_with($type, 'FO_')) {
            return 'FO';
        }

        if ($type === 'BACKBONE' || str_starts_with($type, 'BH')) {
            return 'BACKBONE';
        }

        return $type;
    }

    private function normalizeClassification(?string $classification): string
    {
        $classification = strtoupper(trim((string) $classification));

        if (in_array($classification, ['-', 'FDD', 'ONLY FDD', 'ONLY_FDD'], true)) {
            return 'ONLY_FDD';
        }

        return $classification;
    }

    private function normalizeClassificationFilter(?string $classification): string
    {
        $classification = strtoupper(trim((string) $classification));

        if (in_array($classification, ['FDD', 'ONLY FDD'], true)) {
            return 'ONLY_FDD';
        }

        return $classification;
    }

    private function isTddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(TC|TD|TDD)$/i', trim((string) $site));
    }

    private function isFddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(LM|LMB|LM2|FDD|BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$/i', trim((string) $site));
    }

    private function isTfSiteName(?string $site): bool
    {
        return (bool) preg_match('/_TF$/i', trim((string) $site));
    }

    private function normalizeImportedType(string $type): string
    {
        $type = strtoupper(trim($type));
        $type = str_replace([' ', '-', '/', '+'], '_', $type);
        $type = preg_replace('/_+/', '_', $type);

        if (in_array($type, ['BH_TT', 'BHTT', 'TT'], true)) {
            return 'BH_TT';
        }

        if (in_array($type, ['BH_OO', 'BHOO', 'OO'], true)) {
            return 'BH_OO';
        }

        if ($type === 'FH') {
            return 'FH';
        }

        if ($type === 'FO' || str_starts_with($type, 'FO_')) {
            return 'FO';
        }

        if ($type === 'BACKBONE' || str_starts_with($type, 'BH')) {
            return 'BACKBONE';
        }

        if (in_array($type, ['NON_DEFINI', 'NONE', 'N_A', 'NA', ''], true)) {
            return 'NON_DEFINI';
        }

        return $type;
    }

    private function detectBackboneTypeFromValue(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace([' ', '-', '/', '+'], '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        if (preg_match('/(?:^|_)(TT)(?:_|$)/', $value)) {
            return 'BH_TT';
        }

        if (preg_match('/(?:^|_)(OO)(?:_|$)/', $value)) {
            return 'BH_OO';
        }

        return 'BACKBONE';
    }

    private function inferBackboneCapacityType(?string $type, ?string $site = null, ?string $siteFdd = null, ?string $siteTdd = null): string
    {
        foreach ([$type, $site, $siteFdd, $siteTdd] as $candidate) {
            $normalized = $this->detectBackboneTypeFromValue($candidate);
            if (in_array($normalized, ['BH_TT', 'BH_OO'], true)) {
                return $normalized;
            }
        }

        return 'BACKBONE';
    }

    private function buildPortCapacityMap(EntityManagerInterface $em): array
    {
        if ($this->portCapacitiesCache !== null) {
            return $this->portCapacitiesCache;
        }

        if (!$this->tableExists($em, 'port_data')) {
            $this->portCapacitiesCache = [];
            return [];
        }

        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT site, port_no, trafic
             FROM (
                SELECT site, port_no, trafic, date_import,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, :suffixPattern, \'\', \'i\'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
             ) latest_ports
             WHERE date_import = latest_site_date AND row_num = 1
             ORDER BY UPPER(site), port_no',
            ['suffixPattern' => '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$']
        );
        $capacities = [];

        foreach ($rows as $row) {
            $site = trim((string) $row['site']);
            if ($site === '') {
                continue;
            }

            $siteUpper = strtoupper($site);
            if (!isset($capacities[$siteUpper])) {
                $capacities[$siteUpper] = ['tdd' => 0.0, 'fdd' => 0.0];
            }

            $traffic = (float) ($row['trafic'] ?? 0);
            if ($traffic <= 0) {
                continue;
            }

            if ($this->isTddSiteName($site)) {
                $capacities[$siteUpper]['tdd'] = max($capacities[$siteUpper]['tdd'], $traffic);
            } elseif ($this->isFddSiteName($site)) {
                $capacities[$siteUpper]['fdd'] = max($capacities[$siteUpper]['fdd'], $traffic);
            } elseif ((int) $row['port_no'] === 0) {
                $capacities[$siteUpper]['tdd'] = max($capacities[$siteUpper]['tdd'], $traffic);
            } elseif ((int) $row['port_no'] === 1) {
                $capacities[$siteUpper]['fdd'] = max($capacities[$siteUpper]['fdd'], $traffic);
            }
        }

        $result = [];
        foreach ($capacities as $site => $ports) {
            if (($ports['tdd'] + $ports['fdd']) <= 0) {
                continue;
            }

            $result[$site] = $ports;
            $cleanKey = strtoupper($this->cleanSiteName($site));
            if ($cleanKey !== '') {
                if (!isset($result[$cleanKey])) {
                    $result[$cleanKey] = ['tdd' => 0.0, 'fdd' => 0.0];
                }

                $result[$cleanKey]['tdd'] = max($result[$cleanKey]['tdd'], $ports['tdd']);
                $result[$cleanKey]['fdd'] = max($result[$cleanKey]['fdd'], $ports['fdd']);
            }
        }

        uksort($result, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->portCapacitiesCache = $result;
        return $result;
    }

    private function buildImportedCapacityMap(EntityManagerInterface $em, array $types = []): array
    {
        $cacheKey = implode('|', $types);
        
        if ($cacheKey === 'FO' && $this->foCapacitiesCache !== null) {
            return $this->foCapacitiesCache;
        }
        if ($cacheKey === 'FH' && $this->fhCapacitiesCache !== null) {
            return $this->fhCapacitiesCache;
        }
        if (in_array('BACKBONE', $types) && $this->backboneCapacitiesCache !== null) {
            return $this->backboneCapacitiesCache;
        }

        if (!$this->tableExists($em, 'capacite_site')) {
            return [];
        }

        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT site, type_trans, capacite_mbps FROM capacite_site WHERE capacite_mbps > 0 ORDER BY date_mise_ajour DESC, id DESC'
        );

        $capacities = [];
        $wanted = [];
        foreach ($types as $type) {
            $wanted[] = $this->normalizeImportedType((string) $type);
        }

        foreach ($rows as $row) {
            $site = trim((string) $row['site']);
            $capacity = (float) ($row['capacite_mbps'] ?? 0);
            if ($site === '' || $capacity <= 0) {
                continue;
            }

            $typeTrans = $this->normalizeImportedType((string) ($row['type_trans'] ?? ''));
            if (!empty($wanted) && !in_array($typeTrans, $wanted, true)) {
                continue;
            }

            $keys = [
                strtoupper($site),
                strtoupper($this->cleanSiteName($site)),
            ];

            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }

                if (!isset($capacities[$key])) {
                    $capacities[$key] = $capacity;
                }
            }
        }

        uksort($capacities, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        
        if ($cacheKey === 'FO') {
            $this->foCapacitiesCache = $capacities;
        } elseif ($cacheKey === 'FH') {
            $this->fhCapacitiesCache = $capacities;
        } elseif (in_array('BACKBONE', $types)) {
            $this->backboneCapacitiesCache = $capacities;
        }
        
        return $capacities;
    }

    private function mergeCapacities(array $capacityMaps): array
    {
        $merged = [];

        foreach ($capacityMaps as $map) {
            if (!is_array($map)) {
                continue;
            }

            foreach ($map as $key => $value) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }

        uksort($merged, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $merged;
    }

    private function resolveCapacity(array $capacityMaps, array $priorityTypes, ?string $site): float
    {
        foreach ($priorityTypes as $type) {
            if (!isset($capacityMaps[$type])) {
                continue;
            }

            $capacity = $this->findCapacityInMap($capacityMaps[$type], $site);
            if ($capacity > 0) {
                return $capacity;
            }
        }

        return 0.0;
    }

    private function findCapacityInMap(array $capacities, ?string $site): float
    {
        $site = trim((string) $site);
        if ($site === '') {
            return 0.0;
        }

        $key = strtoupper($site);
        
        if (isset($capacities[$key])) {
            return (float) $capacities[$key];
        }
        
        $cleanKey = strtoupper($this->cleanSiteName($site));
        if (isset($capacities[$cleanKey])) {
            return (float) $capacities[$cleanKey];
        }
        
        return 0.0;
    }

    private function findPortCapacityInMap(array $portCapacities, ?string $site): array
    {
        $site = trim((string) $site);
        if ($site === '') {
            return ['tdd' => 0.0, 'fdd' => 0.0];
        }

        $keys = [strtoupper($site)];
        if (!$this->isTfSiteName($site)) {
            $keys[] = strtoupper($this->cleanSiteName($site));
        }

        foreach ($keys as $key) {
            if ($key !== '' && isset($portCapacities[$key])) {
                return [
                    'tdd' => (float) ($portCapacities[$key]['tdd'] ?? 0),
                    'fdd' => (float) ($portCapacities[$key]['fdd'] ?? 0),
                ];
            }
        }

        return ['tdd' => 0.0, 'fdd' => 0.0];
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

    private function findBackboneCapacity(array $capacities, ?string $site, string $backboneType): float
    {
        $site = trim((string) $site);
        if ($site === '') {
            return 0.0;
        }

        $key = strtoupper($site);

        $typeKey = $key . '_' . $backboneType;
        if (isset($capacities[$typeKey])) {
            return (float) $capacities[$typeKey];
        }

        $genericKey = $key . '_BACKBONE';
        if (isset($capacities[$genericKey])) {
            return (float) $capacities[$genericKey];
        }

        return $this->findCapacityInMap($capacities, $site);
    }

    private function applyUtilizationRates(array &$site): void
    {
        $cls = strtoupper(preg_replace('/\s+/u', '', (string) ($site['classification'] ?? '')));
        $isCotrans = str_contains($cls, 'COTRANS');

        if (array_key_exists('taux_utilisation', $site)
            && array_key_exists('taux_utilisation_tdd', $site)
            && array_key_exists('taux_utilisation_fdd', $site)) {
            // COTRANS/NO_COTRANS : forcer le taux global à null même si la BD a une ancienne valeur
            if ($isCotrans) {
                $site['taux_utilisation'] = null;
            }
            return;
        }

        $rates = $this->capacityUtilizationService->computeUtilizationRates(
            (float) ($site['max_trafic'] ?? 0),
            (float) ($site['max_tdd'] ?? $site['max_trafic_tdd'] ?? 0),
            (float) ($site['max_fdd'] ?? $site['max_trafic_fdd'] ?? 0),
            (string) ($site['classification'] ?? ''),
            (float) ($site['capacite_tdd_mbps'] ?? 0),
            (float) ($site['capacite_fdd_mbps'] ?? 0),
            (float) ($site['capacite_mbps'] ?? 0)
        );

        $site['taux_utilisation'] = $rates['taux_utilisation'];
        $site['taux_utilisation_tdd'] = $rates['taux_utilisation_tdd'];
        $site['taux_utilisation_fdd'] = $rates['taux_utilisation_fdd'];
    }

    private function enrichCapacityColumns(EntityManagerInterface $em, array $results, ?string $forcedType = null): array
    {
        $portCapacities = $this->buildPortCapacityMap($em);
        $foCapacities = $this->buildImportedCapacityMap($em, ['FO']);
        $fhCapacities = $this->buildImportedCapacityMap($em, ['FH']);
        $backboneCapacities = $this->buildImportedCapacityMap($em, ['BACKBONE', 'BH_TT', 'BH_OO']);

        foreach ($results as &$row) {
            $type = $this->normalizeTransmissionType($forcedType ?: ($row['type_trans'] ?? ''));
            $classification = $this->normalizeClassification($row['classification'] ?? '');

            if ($type === 'NON_DEFINI') {
                $row['capacite_tdd_mbps'] = 0.0;
                $row['capacite_fdd_mbps'] = 0.0;
                $row['capacite_mbps']     = 0.0;
                $row['classification']         = $classification;
                $row['classification_display'] = $classification === 'ONLY_FDD' ? 'ONLY FDD' : $classification;
                $this->applyUtilizationRates($row);
                continue;
            }

            $existingCapacity = (float) ($row['capacite_mbps'] ?? 0);

            $siteTdd = $row['site_tdd'] ?? null;
            $siteFdd = $row['site_fdd'] ?? null;
            $sitePorts = $this->findPortCapacityInMap($portCapacities, $row['site'] ?? null);
            $tddPorts = $this->findPortCapacityInMap($portCapacities, $siteTdd);
            $fddPorts = $this->findPortCapacityInMap($portCapacities, $siteFdd);
            $tddCapacity = 0.0;
            $fddCapacity = 0.0;
            $mainCapacity = 0.0;
            $hasImportedCapacity = false;
            
            if ($type === 'BACKBONE') {
                $backboneType = $this->inferBackboneCapacityType(
                    $forcedType ?: ($row['type_trans'] ?? null),
                    $row['site'] ?? null,
                    $siteFdd,
                    $siteTdd
                );
                $mainCapacity = $this->findBackboneCapacity($backboneCapacities, $row['site'] ?? null, $backboneType);
                $importedTddCapacity = $this->findBackboneCapacity($backboneCapacities, $siteTdd, $backboneType);
                $importedFddCapacity = $this->findBackboneCapacity($backboneCapacities, $siteFdd, $backboneType);
                $hasImportedCapacity = ($mainCapacity + $importedTddCapacity + $importedFddCapacity) > 0;
                if ($hasImportedCapacity) {
                    $tddCapacity = $importedTddCapacity;
                    $fddCapacity = $importedFddCapacity;
                    if ($mainCapacity > 0 && ($tddCapacity + $fddCapacity) <= 0) {
                        $this->assignCapacityByPort(
                            $mainCapacity,
                            $sitePorts,
                            $classification === 'ONLY_FDD' ? 'fdd' : 'tdd',
                            $tddCapacity,
                            $fddCapacity
                        );
                    }
                } else {
                    if ($tddCapacity <= 0 && $fddCapacity <= 0) {
                        $this->assignCapacityByPort($mainCapacity, $sitePorts, $classification === 'ONLY_FDD' ? 'fdd' : null, $tddCapacity, $fddCapacity);
                    }
                }
            } elseif ($type === 'FO') {
                $mainCapacity = $this->findCapacityInMap($foCapacities, $row['site'] ?? null);
                $importedTddCapacity = $this->findCapacityInMap($foCapacities, $siteTdd);
                $importedFddCapacity = $this->findCapacityInMap($foCapacities, $siteFdd);
                $hasImportedCapacity = ($mainCapacity + $importedTddCapacity + $importedFddCapacity) > 0;
                if ($hasImportedCapacity) {
                    $tddCapacity = $importedTddCapacity;
                    $fddCapacity = $importedFddCapacity;
                    if ($mainCapacity > 0 && ($tddCapacity + $fddCapacity) <= 0) {
                        $this->assignCapacityByPort($mainCapacity, $sitePorts, 'tdd', $tddCapacity, $fddCapacity);
                    }
                } else {
                    $tddCapacity = max($sitePorts['tdd'], $tddPorts['tdd'], $fddPorts['tdd']);
                    $fddCapacity = max($sitePorts['fdd'], $tddPorts['fdd'], $fddPorts['fdd']);
                    $mainCapacity = max($tddCapacity, $fddCapacity);
                }
            } else {
                $mainCapacity = $this->findCapacityInMap($fhCapacities, $row['site'] ?? null);
                $importedTddCapacity = $this->findCapacityInMap($fhCapacities, $siteTdd);
                $importedFddCapacity = $this->findCapacityInMap($fhCapacities, $siteFdd);
                $hasImportedCapacity = ($mainCapacity + $importedTddCapacity + $importedFddCapacity) > 0;
                if ($hasImportedCapacity) {
                    $tddCapacity = $importedTddCapacity;
                    $fddCapacity = $importedFddCapacity;
                    if ($mainCapacity > 0 && ($tddCapacity + $fddCapacity) <= 0) {
                        $this->assignCapacityByPort($mainCapacity, $sitePorts, 'fdd', $tddCapacity, $fddCapacity);
                    }
                } else {
                    if ($tddCapacity <= 0 && $fddCapacity <= 0) {
                        $fallbackSide = $classification === 'ONLY_FDD' ? 'fdd' : null;
                        $this->assignCapacityByPort($mainCapacity, $sitePorts, $fallbackSide, $tddCapacity, $fddCapacity);
                    }
                }
            }

            if (!$hasImportedCapacity && $mainCapacity > 0 && ($tddCapacity + $fddCapacity) <= 0) {
                if ($type === 'FH' || $classification === 'ONLY_FDD') {
                    $fddCapacity = $mainCapacity;
                } else {
                    $tddCapacity = $mainCapacity;
                }
            }

            if ($classification === 'TF') {
                $tfSite = $this->cleanSiteName((string) ($row['site'] ?? '')) . '_TF';
                $tfImportedCapacity = match ($type) {
                    'FO' => $this->findCapacityInMap($foCapacities, $tfSite),
                    'FH' => $this->findCapacityInMap($fhCapacities, $tfSite),
                    default => $this->findBackboneCapacity($backboneCapacities, $tfSite, $this->inferBackboneCapacityType($forcedType ?: ($row['type_trans'] ?? null), $tfSite)),
                };
                $tfPorts = $this->findPortCapacityInMap($portCapacities, $tfSite);
                if ($tfImportedCapacity > 0) {
                    $mainCapacity = $tfImportedCapacity;
                } elseif (($tfPorts['tdd'] + $tfPorts['fdd']) > 0) {
                    $mainCapacity = $tfPorts['tdd'] + $tfPorts['fdd'];
                } elseif ($mainCapacity <= 0 && $existingCapacity > 0) {
                    $mainCapacity = $existingCapacity;
                }
                $tddCapacity = 0.0;
                $fddCapacity = $mainCapacity;
            }
            
            if ($mainCapacity <= 0 && $existingCapacity > 0) {
                $mainCapacity = $existingCapacity;
            }
            
            if ($type === 'BACKBONE' && $existingCapacity > 0 && $existingCapacity < $mainCapacity) {
                $mainCapacity = $existingCapacity;
            }

            if (!$hasImportedCapacity && ($tddCapacity + $fddCapacity) <= 0 && $existingCapacity > 0) {
                if ($classification === 'ONLY_FDD') {
                    $fddCapacity = $existingCapacity;
                } else {
                    $this->assignCapacityByPort($existingCapacity, $sitePorts, 'tdd', $tddCapacity, $fddCapacity);
                }
            }

            $row['capacite_tdd_mbps'] = $tddCapacity;
            $row['capacite_fdd_mbps'] = $fddCapacity;
            $effectiveCapacity = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $tddCapacity,
                $fddCapacity,
                $tddCapacity + $fddCapacity > 0 ? $tddCapacity + $fddCapacity : $existingCapacity
            );
            $row['capacite_mbps'] = $effectiveCapacity;

            if ($effectiveCapacity <= 0 && $existingCapacity > 0) {
                $row['capacite_mbps'] = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                    $classification,
                    $tddCapacity,
                    $fddCapacity,
                    $existingCapacity
                );
            }

            $this->applyUtilizationRates($row);

            $row['classification'] = $classification;
            $row['classification_display'] = $classification === 'ONLY_FDD' ? 'ONLY FDD' : $classification;
        }
        
        return $results;
    }

    private function postProcessResults(array $results, ?string $dateDebut, ?string $dateFin): array
    {
        if (!$dateDebut || !$dateFin) {
            foreach ($results as &$row) {
                $row['max_tdd'] = (float)($row['max_trafic_tdd'] ?? 0);
                $row['max_fdd'] = (float)($row['max_trafic_fdd'] ?? 0);
                $row['nombre_occurrences_tdd'] = (int)($row['nombre_occurrences_tdd'] ?? 0);
                $row['nombre_occurrences_fdd'] = (int)($row['nombre_occurrences_fdd'] ?? 0);
                $row['dropcong_tdd'] = (int)($row['dropcong_tdd'] ?? 0);
                $row['dropcong_fdd'] = (int)($row['dropcong_fdd'] ?? 0);
                $row['dropcong_tf'] = (int)($row['dropcong_tf'] ?? 0);
                $row['capacite_mbps'] = (float)($row['capacite_mbps'] ?? 0);
                $row['duree_jours'] = max(1, (int) ($row['duree_jours'] ?? 7));
                $row['dropcong_kpi'] = strtolower((string) ($row['dropcong_kpi'] ?? 'non')) === 'oui' ? 'oui' : 'non';
            }
            unset($row);

            return $results;
        }

        $duree = $this->calculerDureeEnJours($dateDebut, $dateFin);
        $dureeKey = (string) $duree;

        foreach ($results as &$row) {
            $row['duree_jours'] = $duree;
            $statsJson = json_decode($row['stats_json'] ?? '{}', true);
            $stats = $this->extractStatsForDuree($statsJson, $dureeKey);

            if ($stats['max_trafic'] !== null) {
                $row['max_trafic'] = $stats['max_trafic'];
                $row['nombre_occurrences'] = $stats['nombre_occurrences'];
                $row['date_max'] = $stats['date_max'];
                $row['max_tdd'] = $stats['max_tdd'];
                $row['max_fdd'] = $stats['max_fdd'];
                $row['nombre_occurrences_tdd'] = $stats['nombre_occurrences_tdd'];
                $row['nombre_occurrences_fdd'] = $stats['nombre_occurrences_fdd'];
            } else {
                $row['max_tdd'] = (float)($row['max_trafic_tdd'] ?? 0);
                $row['max_fdd'] = (float)($row['max_trafic_fdd'] ?? 0);
                $row['nombre_occurrences_tdd'] = (int)($row['nombre_occurrences_tdd'] ?? 0);
                $row['nombre_occurrences_fdd'] = (int)($row['nombre_occurrences_fdd'] ?? 0);
            }
            $row['dropcong_tdd'] = (int)($row['dropcong_tdd'] ?? 0);
            $row['dropcong_fdd'] = (int)($row['dropcong_fdd'] ?? 0);
            $row['dropcong_tf'] = (int)($row['dropcong_tf'] ?? 0);
            $row['capacite_mbps'] = (float)($row['capacite_mbps'] ?? 0);
            $row['dropcong_kpi'] = strtolower((string) ($row['dropcong_kpi'] ?? 'non')) === 'oui' ? 'oui' : 'non';
        }
        unset($row);

        return $results;
    }

    private function buildPeriodeSeuilsContext(?string $dateDebut, ?string $dateFin): array
    {
        if (!$dateDebut || !$dateFin) {
            return [
                'periode_duree_jours' => null,
                'seuil_critique_periode' => null,
                'seuil_observation_periode' => null,
            ];
        }

        $duree = $this->calculerDureeEnJours($dateDebut, $dateFin);

        return [
            'periode_duree_jours' => $duree,
            'seuil_critique_periode' => $this->siteStatusDecisionService->getCriticalThreshold($duree),
            'seuil_observation_periode' => $this->siteStatusDecisionService->getWatchThreshold($duree),
        ];
    }

    private function buildLatestSiteEtatMap(EntityManagerInterface $em): array
    {
        if (!$this->tableExists($em, 'site_etat')) {
            return [];
        }

        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps, taux_utilisation, variation_j1, variation_j7, date_calcul
             FROM site_etat'
        );

        $map = [];
        foreach ($rows as $row) {
            $site = trim((string) ($row['site'] ?? ''));
            if ($site === '') {
                continue;
            }
            $map[strtoupper($site)] = $row;
            $clean = strtoupper($this->cleanSiteName($site));
            if ($clean !== '') {
                $map[$clean] = $row;
            }
        }

        return $map;
    }

    private function resolveSiteEtatLabel(array $row, array $siteEtatMap): ?string
    {
        foreach (['site', 'site_fdd', 'site_tdd'] as $field) {
            $site = trim((string) ($row[$field] ?? ''));
            if ($site === '') {
                continue;
            }

            $key = strtoupper($site);
            if (isset($siteEtatMap[$key]['etat'])) {
                $etat = strtoupper((string) $siteEtatMap[$key]['etat']);
                return match ($etat) {
                    'CONGESTIONNE', 'CONGESTION' => 'CONGESTION',
                    'RISQUE_DE_CONGESTION' => 'RISQUE DE CONGESTION',
                    'BRIDE', 'BRIDAGE' => 'BRIDAGE',
                    default => 'OK',
                };
            }
        }

        return null;
    }

    private function getEtatSite(float $maxTrafic, float $capaciteMbps, int $occurrences, ?int $dureeJours = null): string
    {
        if ($capaciteMbps <= 0 || $maxTrafic <= 0) {
            return 'OK';
        }

        $taux = ($maxTrafic / $capaciteMbps) * 100;

        if ($taux >= SiteStatusDecisionService::CONGESTION_RATE_THRESHOLD && $occurrences >= SiteStatusDecisionService::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return 'CONGESTION';
        }

        if ($taux >= SiteStatusDecisionService::CONGESTION_RATE_THRESHOLD && $occurrences < SiteStatusDecisionService::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return SiteStatusDecisionService::RISK_CONGESTION_CODE;
        }

        if ($taux < SiteStatusDecisionService::CONGESTION_RATE_THRESHOLD && $occurrences >= SiteStatusDecisionService::CRITICAL_OCCURRENCES_PER_WEEK) {
            return 'BRIDAGE';
        }

        return 'OK';
    }

    // ==================== ROUTES OPTIMISÉES ====================

    #[Route('/superuser/fo', name: 'superuser_fo')]
    public function fo(EntityManagerInterface $em, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min((int) $request->query->get('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $offset = ($page - 1) * $limit;
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesPaginated($em, 'FO', $search, $classFilter, $dateDebut, $dateFin, $sort, $limit, $offset);
        $total = $this->getTotalSitesCount($em, 'FO', $search, $classFilter);
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);
        
        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }
        
        $stats = $this->statsService->getStatsByType('FO');
        
        return $this->render('dashboard/_transmission_table.html.twig', array_merge([
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'FO',
            'titre'       => 'Fibre Optique',
            'search'      => $search,
            'classFilter' => $classFilter,
            'date_debut'  => $dateDebut,
            'date_fin'    => $dateFin,
            'sort'        => $sort,
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => ceil($total / $limit),
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ], $this->buildPeriodeSeuilsContext($dateDebut, $dateFin)));
    }

    #[Route('/superuser/fh', name: 'superuser_fh')]
    public function fh(EntityManagerInterface $em, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min((int) $request->query->get('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $offset = ($page - 1) * $limit;
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesPaginated($em, 'FH', $search, $classFilter, $dateDebut, $dateFin, $sort, $limit, $offset);
        $total = $this->getTotalSitesCount($em, 'FH', $search, $classFilter);
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);
        
        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }
        
        $stats = $this->statsService->getStatsByType('FH');
        
        return $this->render('dashboard/_transmission_table.html.twig', array_merge([
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'FH',
            'titre'       => 'Faisceaux Hertziens',
            'search'      => $search,
            'classFilter' => $classFilter,
            'date_debut'  => $dateDebut,
            'date_fin'    => $dateFin,
            'sort'        => $sort,
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => ceil($total / $limit),
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ], $this->buildPeriodeSeuilsContext($dateDebut, $dateFin)));
    }

    #[Route('/superuser/backbone', name: 'superuser_backbone')]
    public function backbone(EntityManagerInterface $em, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min((int) $request->query->get('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $offset = ($page - 1) * $limit;
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getBackboneSitesPaginated($em, $search, $classFilter, $dateDebut, $dateFin, $sort, $limit, $offset);
        $total = $this->getTotalBackboneSitesCount($em, $search, $classFilter);
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);
        
        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }
        
        $stats = $this->statsService->getStatsByType('BACKBONE');
        
        return $this->render('dashboard/_transmission_table.html.twig', array_merge([
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'BACKBONE',
            'titre'       => 'Backhaul Sharing',
            'search'      => $search,
            'classFilter' => $classFilter,
            'date_debut'  => $dateDebut,
            'date_fin'    => $dateFin,
            'sort'        => $sort,
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => ceil($total / $limit),
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ], $this->buildPeriodeSeuilsContext($dateDebut, $dateFin)));
    }

    // ⚡ ROUTE TOUS SITES OPTIMISÉE AVEC PAGINATION OBLIGATOIRE
    #[Route('/superuser/tous', name: 'superuser_tous')]
    public function tousSites(EntityManagerInterface $em, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min((int) $request->query->get('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $offset = ($page - 1) * $limit;
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        // Récupération paginée des sites
        $sites = $this->getAllSitesPaginated($em, $search, $classFilter, $dateDebut, $dateFin, $sort, $limit, $offset);
        $total = $this->getTotalAllSitesCount($em, $search, $classFilter);
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);
        
        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }
        
        $stats = [
            'total'           => $total,
            'total_occ'       => array_sum(array_column($sites, 'nombre_occurrences')),
            'max_trafic'      => !empty($sites) ? max(array_column($sites, 'max_trafic')) : 0,
            'sites_critiques' => count(array_filter($sites, fn($s) => ($s['statut'] ?? 'OK') === 'CRITIQUE')),
            'total_tf'        => count(array_filter($sites, fn($s) => $s['classification'] === 'TF')),
            'total_cotrans'   => count(array_filter($sites, fn($s) => $s['classification'] === 'COTRANS')),
            'total_nocotrans' => count(array_filter($sites, fn($s) => $s['classification'] === 'NO_COTRANS')),
            'total_nontdd'    => count(array_filter($sites, fn($s) => $s['classification'] === 'NON_TDD')),
        ];
        
        return $this->render('dashboard/_transmission_table.html.twig', array_merge([
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'TOUS',
            'titre'       => 'Résultat Plan Data',
            'search'      => $search,
            'classFilter' => $classFilter,
            'date_debut'  => $dateDebut,
            'date_fin'    => $dateFin,
            'sort'        => $sort,
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => ceil($total / $limit),
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ], $this->buildPeriodeSeuilsContext($dateDebut, $dateFin)));
    }

    // ⚡ NOUVELLES MÉTHODES PAGINÉES
    private function getAllSitesPaginated(EntityManagerInterface $em, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc', int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return $this->appendManagedSites($em, [], $search, $classFilter);
        }

        $dropCongSelect = $this->buildDropCongSelectSql($em);

        $sql = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                COALESCE(a.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                COALESCE(a.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                a.capacite_mbps, a.duree_jours,
                a.stats_json,
                ' . $dropCongSelect . ',
                CASE WHEN COALESCE(a.drop_cong_kpi, false) THEN \'oui\' ELSE \'non\' END AS dropcong_kpi
            FROM analyse_resultat a
            WHERE a.date_analyse = :lastDate
        ';

        $params = ['lastDate' => $lastDate];

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        $orderBy = match ($sort) {
            'occ_asc' => 'ORDER BY a.nombre_occurrences ASC, a.max_trafic DESC',
            'trafic_desc' => 'ORDER BY a.max_trafic DESC, a.nombre_occurrences DESC',
            default => 'ORDER BY a.nombre_occurrences DESC, a.max_trafic DESC',
        };
        $sql .= " " . $orderBy;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $results = $em->getConnection()->fetchAllAssociative($sql, $params);
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        $results = $this->enrichCapacityColumns($em, $results);
        
        return $results;
    }

    private function getSitesPaginated(EntityManagerInterface $em, string $type, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc', int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return $this->appendManagedSites($em, [], $search, $classFilter, $type);
        }

        $typeUpper = strtoupper($type);
        $dropCongSelect = $this->buildDropCongSelectSql($em);

        $sql = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                COALESCE(a.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                COALESCE(a.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                a.capacite_mbps, a.duree_jours,
                a.stats_json,
                ' . $dropCongSelect . ',
                CASE WHEN COALESCE(a.drop_cong_kpi, false) THEN \'oui\' ELSE \'non\' END AS dropcong_kpi
            FROM analyse_resultat a
            WHERE UPPER(a.type_trans) ' . (($typeUpper === 'FO' || $typeUpper === 'FH') ? 'LIKE :type_pattern' : '= :type') . ' AND a.date_analyse = :lastDate
        ';

        $params = ['lastDate' => $lastDate];
        if ($typeUpper === 'FO' || $typeUpper === 'FH') {
            $params['type_pattern'] = $typeUpper . '%';
        } else {
            $params['type'] = $typeUpper;
        }

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        $orderBy = match ($sort) {
            'occ_asc' => 'ORDER BY a.nombre_occurrences ASC, a.max_trafic DESC',
            'trafic_desc' => 'ORDER BY a.max_trafic DESC, a.nombre_occurrences DESC',
            default => 'ORDER BY a.nombre_occurrences DESC, a.max_trafic DESC',
        };
        $sql .= " " . $orderBy;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $results = $em->getConnection()->fetchAllAssociative($sql, $params);
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        $results = $this->enrichCapacityColumns($em, $results, $typeUpper);
        
        return $results;
    }

    private function getBackboneSitesPaginated(EntityManagerInterface $em, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc', int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return $this->appendManagedSites($em, [], $search, $classFilter, 'BACKBONE');
        }

        $dropCongSelect = $this->buildDropCongSelectSql($em);

        $sql = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                COALESCE(a.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                COALESCE(a.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                a.capacite_mbps, a.duree_jours,
                a.stats_json,
                ' . $dropCongSelect . ',
                CASE WHEN COALESCE(a.drop_cong_kpi, false) THEN \'oui\' ELSE \'non\' END AS dropcong_kpi
            FROM analyse_resultat a
            WHERE (UPPER(a.type_trans) LIKE \'BH%\' OR UPPER(a.type_trans) = \'BACKBONE\') AND a.date_analyse = :lastDate
        ';

        $params = ['lastDate' => $lastDate];

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        $orderBy = match ($sort) {
            'occ_asc' => 'ORDER BY a.nombre_occurrences ASC, a.max_trafic DESC',
            'trafic_desc' => 'ORDER BY a.max_trafic DESC, a.nombre_occurrences DESC',
            default => 'ORDER BY a.nombre_occurrences DESC, a.max_trafic DESC',
        };
        $sql .= " " . $orderBy;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $results = $em->getConnection()->fetchAllAssociative($sql, $params);
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        $results = $this->enrichCapacityColumns($em, $results, 'BACKBONE');
        
        return $results;
    }

    private function getTotalAllSitesCount(EntityManagerInterface $em, string $search = '', string $classFilter = ''): int
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }

        $sql = 'SELECT COUNT(DISTINCT a.id) FROM analyse_resultat a WHERE a.date_analyse = :lastDate';
        $params = ['lastDate' => $lastDate];

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        return (int) $em->getConnection()->fetchOne($sql, $params);
    }

    private function getTotalSitesCount(EntityManagerInterface $em, string $type, string $search = '', string $classFilter = ''): int
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }

        $typeUpper = strtoupper($type);
        $sql = 'SELECT COUNT(DISTINCT a.id) FROM analyse_resultat a WHERE a.date_analyse = :lastDate AND UPPER(a.type_trans) ' . (($typeUpper === 'FO' || $typeUpper === 'FH') ? 'LIKE :type_pattern' : '= :type');
        $params = ['lastDate' => $lastDate];
        
        if ($typeUpper === 'FO' || $typeUpper === 'FH') {
            $params['type_pattern'] = $typeUpper . '%';
        } else {
            $params['type'] = $typeUpper;
        }

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        return (int) $em->getConnection()->fetchOne($sql, $params);
    }

    private function getTotalBackboneSitesCount(EntityManagerInterface $em, string $search = '', string $classFilter = ''): int
    {
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }

        $sql = 'SELECT COUNT(DISTINCT a.id) FROM analyse_resultat a WHERE (UPPER(a.type_trans) LIKE \'BH%\' OR UPPER(a.type_trans) = \'BACKBONE\') AND a.date_analyse = :lastDate';
        $params = ['lastDate' => $lastDate];

        if ($search) {
            $searchCleaned = $this->cleanSiteName($search);
            $sql .= " AND (LOWER(a.site) LIKE :search OR LOWER(a.site_fdd) LIKE :search OR LOWER(a.site_tdd) LIKE :search)";
            $params['search'] = '%' . strtolower($searchCleaned) . '%';
        }

        if ($classFilter) {
            $classFilter = $this->normalizeClassificationFilter($classFilter);
            if ($classFilter === 'ONLY_FDD') {
                $sql .= " AND a.classification IN ('-', 'ONLY_FDD', 'FDD')";
            } else {
                $sql .= " AND a.classification = :class";
                $params['class'] = $classFilter;
            }
        }

        return (int) $em->getConnection()->fetchOne($sql, $params);
    }

    private function enrichSiteDecision(array &$site, array $siteEtatMap): void
    {
        $duree = max(1, (int) ($site['duree_jours'] ?? 7));
        $site['duree_jours'] = $duree;
        $site['seuil_observation'] = $this->siteStatusDecisionService->getWatchThreshold($duree);
        $site['seuil_critique'] = $this->siteStatusDecisionService->getCriticalThreshold($duree);

        $this->applyUtilizationRates($site);

        $decision = $this->siteStatusDecisionService->decide($site, $duree);
        $site['statut'] = $decision['statut'];
        $site['statut_label'] = $decision['label'];

        $site['etat'] = $decision['etat'];
    }

    private function getStatut(int $occurrences, ?int $dureeJours = null): string
    {
        return $this->siteStatusDecisionService->decide(
            ['nombre_occurrences' => $occurrences, 'duree_jours' => $dureeJours ?? 7],
            $dureeJours
        )['statut'];
    }

    // ==================== ROUTES DROPCONG ====================

    #[Route('/superuser/dropcong', name: 'superuser_dropcong')]
    public function dropcong(EntityManagerInterface $em, Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $dropFilter = strtolower((string) $request->query->get('dropcong', ''));
        $sort = (string) $request->query->get('sort', 'drop_desc');
        $rows = [];
        $stats = [
            'total' => 0,
            'oui' => 0,
            'non' => 0,
            'last_import' => null,
        ];

        if ($this->tableExists($em, 'dropcong_resultat')) {
            $conn = $em->getConnection();
            $stats = $conn->fetchAssociative(
                <<<SQL
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN dropcong THEN 1 ELSE 0 END) AS oui,
                    SUM(CASE WHEN dropcong THEN 0 ELSE 1 END) AS non,
                    MAX(date_import) AS last_import
                FROM dropcong_resultat
                SQL
            ) ?: $stats;

            $params = [];
            $where = [];

            if ($search !== '') {
                $where[] = 'UPPER(site) LIKE :search';
                $params['search'] = '%' . mb_strtoupper($search) . '%';
            }

            if (in_array($dropFilter, ['oui', 'non'], true)) {
                $where[] = 'dropcong = :dropcong';
                $params['dropcong'] = $dropFilter === 'oui';
            } else {
                $dropFilter = '';
            }

            $orderBy = match ($sort) {
                'site_asc' => 'site ASC',
                'site_desc' => 'site DESC',
                'drop_asc' => 'dropcong ASC, site ASC',
                default => 'dropcong DESC, site ASC',
            };

            if (!in_array($sort, ['drop_desc', 'drop_asc', 'site_asc', 'site_desc'], true)) {
                $sort = 'drop_desc';
            }

            $rows = $conn->fetchAllAssociative(
                sprintf(
                    <<<SQL
                    SELECT
                        site,
                        CASE WHEN dropcong THEN 'oui' ELSE 'non' END AS dropcong,
                        COALESCE(max_iub, 0) AS max_iub,
                        COALESCE(max_rscgroup, 0) AS max_rscgroup,
                        date_import
                    FROM dropcong_resultat
                    %s
                    ORDER BY %s
                    SQL,
                    $where ? 'WHERE ' . implode(' AND ', $where) : '',
                    $orderBy
                ),
                $params
            );
        }

        return $this->render('dropcong/index.html.twig', [
            'rows' => $rows,
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'oui' => (int) ($stats['oui'] ?? 0),
                'non' => (int) ($stats['non'] ?? 0),
                'last_import' => $stats['last_import'] ?? null,
            ],
            'search' => $search,
            'dropFilter' => $dropFilter,
            'sort' => $sort,
            'tableExists' => $this->tableExists($em, 'dropcong_resultat'),
            'threshold' => 500,
        ]);
    }

    // ==================== EXPORTS EXCEL ====================

    #[Route('/superuser/export-dropcong-excel', name: 'export_dropcong_excel')]
    public function exportDropCongExcel(EntityManagerInterface $em, Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $dropFilter = strtolower((string) $request->query->get('dropcong', ''));
        $sort = (string) $request->query->get('sort', 'drop_desc');
        $threshold = 500;

        $rows = [];

        if ($this->tableExists($em, 'dropcong_resultat')) {
            $conn = $em->getConnection();
            
            $params = [];
            $where = [];

            if ($search !== '') {
                $where[] = 'UPPER(site) LIKE :search';
                $params['search'] = '%' . mb_strtoupper($search) . '%';
            }

            if (in_array($dropFilter, ['oui', 'non'], true)) {
                $where[] = 'dropcong = :dropcong';
                $params['dropcong'] = $dropFilter === 'oui';
            }

            $orderBy = match ($sort) {
                'site_asc' => 'site ASC',
                'site_desc' => 'site DESC',
                'drop_asc' => 'dropcong ASC, site ASC',
                default => 'dropcong DESC, site ASC',
            };

            $rows = $conn->fetchAllAssociative(
                sprintf(
                    <<<SQL
                    SELECT
                        site,
                        CASE WHEN dropcong THEN 'oui' ELSE 'non' END AS dropcong,
                        COALESCE(max_iub, 0) AS max_iub,
                        COALESCE(max_rscgroup, 0) AS max_rscgroup,
                        date_import
                    FROM dropcong_resultat
                    %s
                    ORDER BY %s
                    SQL,
                    $where ? 'WHERE ' . implode(' AND ', $where) : '',
                    $orderBy
                ),
                $params
            );
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Rapport DropCong - Seuil: > ' . $threshold);
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getFont()->setSize(12);

        $subtitle = [];
        if ($search) {
            $subtitle[] = 'Recherche: ' . $search;
        }
        if ($dropFilter) {
            $subtitle[] = 'Filtre DropCong: ' . $dropFilter;
        }
        if ($subtitle) {
            $sheet->setCellValue('A2', implode(' | ', $subtitle));
            $sheet->mergeCells('A2:E2');
            $sheet->getStyle('A2')->getFont()->setItalic(true);
            $rowHeader = 3;
        } else {
            $rowHeader = 2;
        }

        $headers = ['Site', 'DropCong', 'Max IUB', 'Max RscGroup', "Date d'import"];
        $col = 'A';
        $headerRow = $rowHeader;
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $headerRow, $header);
            $sheet->getStyle($col . $headerRow)->getFont()->setBold(true);
            $sheet->getStyle($col . $headerRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F8FAFC');
            $col++;
        }

        $row = $headerRow + 1;
        foreach ($rows as $data) {
            $sheet->setCellValue('A' . $row, $data['site']);
            $sheet->setCellValue('B' . $row, $data['dropcong']);
            $sheet->setCellValue('C' . $row, (int) $data['max_iub']);
            $sheet->setCellValue('D' . $row, (int) $data['max_rscgroup']);
            $sheet->setCellValue('E' . $row, $data['date_import'] ? date('d/m/Y H:i', strtotime($data['date_import'])) : '-');

            if ($data['dropcong'] === 'oui') {
                $sheet->getStyle('B' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FEE2E2');
                $sheet->getStyle('B' . $row)->getFont()->setBold(true);
            }

            $row++;
        }

        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = 'dropcong_export_' . date('Y-m-d_His') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($tempFile);

        $response = new Response(file_get_contents($tempFile));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $fileName
        ));
        unlink($tempFile);

        return $response;
    }

    #[Route('/superuser/export-excel/{type}', name: 'export_transmission_excel')]
    public function exportExcel(EntityManagerInterface $em, Request $request, string $type): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');

        if ($type === 'TOUS') {
            $sites = $this->getAllSitesPaginated($em, $search, $classFilter, $dateDebut, $dateFin, $sort, 10000, 0);
        } elseif ($type === 'BACKBONE') {
            $sites = $this->getBackboneSitesPaginated($em, $search, $classFilter, $dateDebut, $dateFin, $sort, 10000, 0);
        } else {
            $sites = $this->getSitesPaginated($em, $type, $search, $classFilter, $dateDebut, $dateFin, $sort, 10000, 0);
        }
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);

        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $seuilsCtx = $this->buildPeriodeSeuilsContext($dateDebut, $dateFin);
        $titrePeriode = ($dateDebut && $dateFin)
            ? sprintf(
                'Période: %s → %s (%d j) — Seuils dynamiques : critique ≥ %d, observation ≥ %d',
                (new \DateTime($dateDebut))->format('d/m/Y'),
                (new \DateTime($dateFin))->format('d/m/Y'),
                $seuilsCtx['periode_duree_jours'],
                $seuilsCtx['seuil_critique_periode'],
                $seuilsCtx['seuil_observation_periode']
            )
            : 'Toute période';
        $sheet->setCellValue('A1', $titrePeriode);
        $sheet->mergeCells('A1:S1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        
        $headers = [
            'Site', 'Classification', 'Type', 'Max TDD', 'Max FDD', 'Trafic Max',
            'Capacité TDD (Mbps)', 'Capacité FDD (Mbps)', 'Taux Utilisation',
            'Taux Utilisation TDD', 'Taux Utilisation FDD',
            'Occurrences', 'Occurrence TDD', 'Occurrence FDD', 'Statut', 'État',
            'DropCong (tdd)', 'DropCong (fdd)', 'DropCong (tf)',
        ];
        $col = 'A';
        $rowHeader = 2;
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $rowHeader, $header);
            $sheet->getStyle($col . $rowHeader)->getFont()->setBold(true);
            $col++;
        }
        
        $row = 3;
        foreach ($sites as $s) {
            $siteName = strtoupper($this->cleanSiteName((string) ($s['site_clean'] ?? $s['site'] ?? '')));
            if ($siteName === '') {
                $siteName = '—';
            }
            $maxTdd = $s['max_tdd'] ?? 0;
            $maxFdd = $s['max_fdd'] ?? 0;
            $maxTrafic = $s['max_trafic'] ?? 0;
            $occurrences = $s['nombre_occurrences'] ?? 0;
            $occurrencesTdd = $s['nombre_occurrences_tdd'] ?? 0;
            $occurrencesFdd = $s['nombre_occurrences_fdd'] ?? 0;
            $statut = $s['statut'] ?? $this->getStatut($occurrences, (int) ($s['duree_jours'] ?? 7));
            $statutLabel = match ($statut) {
                'SOUS_OBSERVATION', 'SURVEILLANCE' => 'SOUS OBSERVATION',
                default => $statut,
            };
            $statutLabel = $s['statut_label'] ?? $statutLabel;
            $etat = $s['etat'] ?? 'OK';
            $capaciteTdd = (float)($s['capacite_tdd_mbps'] ?? 0);
            $capaciteFdd = (float)($s['capacite_fdd_mbps'] ?? 0);
            $classification = (string) ($s['classification'] ?? '');
            $capacite = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $capaciteTdd,
                $capaciteFdd,
                (float)($s['capacite_mbps'] ?? 0)
            );

            $clsNorm = strtoupper(preg_replace('/\s+/u', '', $classification));
            $tauxUtilisation = $s['taux_utilisation'] ?? null;
            if ($tauxUtilisation === null && !str_contains($clsNorm, 'COTRANS') && $capacite > 0 && $maxTrafic > 0) {
                $tauxUtilisation = $this->capacityUtilizationService->computeUtilizationRate(
                    (float) $maxTrafic,
                    $classification,
                    $capaciteTdd,
                    $capaciteFdd,
                    $capacite
                );
            }
            $tauxUtilisationTdd = $s['taux_utilisation_tdd'] ?? $this->capacityUtilizationService->computeRate((float) $maxTdd, $capaciteTdd);
            $tauxUtilisationFdd = $s['taux_utilisation_fdd'] ?? $this->capacityUtilizationService->computeRate((float) $maxFdd, $capaciteFdd);

            $sheet->setCellValue('A' . $row, $siteName);
            $sheet->setCellValue('B' . $row, $s['classification_display'] ?? $s['classification'] ?? '—');
            $sheet->setCellValue('C' . $row, ($s['type_trans'] ?? 'only FDD') === '-' ? 'only FDD' : ($s['type_trans'] ?? 'only FDD'));
            $sheet->setCellValue('D' . $row, number_format($maxTdd, 2, ',', ''));
            $sheet->setCellValue('E' . $row, number_format($maxFdd, 2, ',', ''));
            $sheet->setCellValue('F' . $row, number_format($maxTrafic, 2, ',', ''));
            $sheet->setCellValue('G' . $row, $capaciteTdd > 0 ? $this->formatDecimalNoRounding($s['capacite_tdd_mbps'] ?? $capaciteTdd) : 0);
            $sheet->setCellValue('H' . $row, $capaciteFdd > 0 ? $this->formatDecimalNoRounding($s['capacite_fdd_mbps'] ?? $capaciteFdd) : 0);
            $sheet->setCellValue('I' . $row, $this->formatTauxUtilisationPourcent($tauxUtilisation !== null ? (float) $tauxUtilisation : null));
            $sheet->setCellValue('J' . $row, $this->formatTauxUtilisationPourcent($tauxUtilisationTdd !== null ? (float) $tauxUtilisationTdd : null));
            $sheet->setCellValue('K' . $row, $this->formatTauxUtilisationPourcent($tauxUtilisationFdd !== null ? (float) $tauxUtilisationFdd : null));
            $sheet->setCellValue('L' . $row, $occurrences);
            $sheet->setCellValue('M' . $row, $occurrencesTdd);
            $sheet->setCellValue('N' . $row, $occurrencesFdd);
            $sheet->setCellValue('O' . $row, $statutLabel);
            $sheet->setCellValue('P' . $row, $etat);
            $sheet->setCellValue('Q' . $row, (int) ($s['dropcong_tdd'] ?? 0));
            $sheet->setCellValue('R' . $row, (int) ($s['dropcong_fdd'] ?? 0));
            $sheet->setCellValue('S' . $row, (int) ($s['dropcong_tf'] ?? 0));
            $row++;
        }

        foreach (range('A', 'S') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        $writer->save($tempFile);
        $response = new Response(file_get_contents($tempFile));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, "sites_{$type}_" . date('Y-m-d') . ".xlsx"));
        unlink($tempFile);
        return $response;
    }

    #[Route('/superuser/export-map-selection', name: 'export_map_selection_excel', methods: ['POST'])]
    public function exportMapSelection(EntityManagerInterface $em, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $selectedSites = json_decode((string) $request->request->get('sites', '[]'), true);
        if (!is_array($selectedSites)) {
            $selectedSites = [];
        }

        $selectedKeys = [];
        $selectedNames = [];
        foreach ($selectedSites as $site) {
            $site = trim((string) $site);
            if ($site === '') {
                continue;
            }

            $selectedNames[] = $site;
            $selectedKeys[mb_strtolower($site)] = true;
            $selectedKeys[mb_strtolower($this->cleanSiteName($site))] = true;
        }

        $sites = $this->getAllSitesPaginated($em, '', '', '', '', 'occ_desc', 5000, 0);
        $siteEtatMap = $this->buildLatestSiteEtatMap($em);

        foreach ($sites as &$site) {
            $this->enrichSiteDecision($site, $siteEtatMap);
        }
        unset($site);

        $sites = array_values(array_filter($sites, function (array $site) use ($selectedKeys): bool {
            if ($selectedKeys === []) {
                return false;
            }

            $name = trim((string) ($site['site'] ?? $site['site_clean'] ?? ''));
            $clean = $this->cleanSiteName($name);

            return isset($selectedKeys[mb_strtolower($name)])
                || isset($selectedKeys[mb_strtolower($clean)]);
        }));

        $foundKeys = [];
        foreach ($sites as $site) {
            $name = trim((string) ($site['site'] ?? $site['site_clean'] ?? ''));
            if ($name !== '') {
                $foundKeys[mb_strtolower($name)] = true;
                $foundKeys[mb_strtolower($this->cleanSiteName($name))] = true;
            }
        }

        $missingNames = array_values(array_filter($selectedNames, function (string $name) use ($foundKeys): bool {
            return !isset($foundKeys[mb_strtolower($name)])
                && !isset($foundKeys[mb_strtolower($this->cleanSiteName($name))]);
        }));

        if ($missingNames !== []) {
            $fallbackSites = $this->getLatestKnownSitesForExport($em, $missingNames);
            $fallbackSites = $this->postProcessResults($fallbackSites, '', '');
            $fallbackSites = $this->enrichCapacityColumns($em, $fallbackSites);
            foreach ($fallbackSites as &$site) {
                $this->enrichSiteDecision($site, $siteEtatMap);
            }
            unset($site);

            $sites = array_merge($sites, $fallbackSites);
        }

        return $this->buildMapSelectionExcelResponse($sites);
    }

    private function getLatestKnownSitesForExport(EntityManagerInterface $em, array $siteNames): array
    {
        $siteNames = array_values(array_unique(array_filter(array_map(
            static fn (string $site): string => mb_strtolower(trim($site)),
            $siteNames
        ))));

        if ($siteNames === []) {
            return [];
        }

        $dropCongSelect = $this->buildDropCongSelectSql($em);

        return $em->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (LOWER(a.site))
                a.id, a.site, a.classification, a.type_trans, a.date_analyse,
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                COALESCE(a.nombre_occurrences_tdd, 0) AS nombre_occurrences_tdd,
                COALESCE(a.nombre_occurrences_fdd, 0) AS nombre_occurrences_fdd,
                a.capacite_mbps, a.duree_jours,
                a.stats_json,
                ' . $dropCongSelect . ',
                CASE WHEN COALESCE(a.drop_cong_kpi, false) THEN \'oui\' ELSE \'non\' END AS dropcong_kpi
             FROM analyse_resultat a
             WHERE LOWER(a.site) IN (:sites)
             ORDER BY LOWER(a.site), a.date_analyse DESC, a.id DESC',
            ['sites' => $siteNames],
            ['sites' => ArrayParameterType::STRING]
        );
    }

    private function buildMapSelectionExcelResponse(array $sites): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Selection carte Tunisie');
        $sheet->mergeCells('A1:Q1');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $headers = [
            'Site',
            'Classification',
            'Type',
            'Max TDD',
            'Max FDD',
            'Trafic Max',
            "Capacit\u{00E9} TDD (Mbps)",
            "Capacit\u{00E9} FDD (Mbps)",
            'Taux Utilisation',
            'Occurrences',
            'Occurrence TDD',
            'Occurrence FDD',
            'Statut',
            "\u{00C9}tat",
            'DropCong (tdd)',
            'DropCong (fdd)',
            'DropCong (tf)',
        ];

        foreach ($headers as $index => $header) {
            $column = chr(ord('A') + $index);
            $sheet->setCellValue($column . '2', $header);
            $sheet->getStyle($column . '2')->getFont()->setBold(true);
        }

        $row = 3;
        foreach ($sites as $site) {
            $maxTrafic = (float) ($site['max_trafic'] ?? 0);
            $capaciteTdd = (float) ($site['capacite_tdd_mbps'] ?? 0);
            $capaciteFdd = (float) ($site['capacite_fdd_mbps'] ?? 0);
            $classification = (string) ($site['classification'] ?? '');
            $capaciteTotal = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $capaciteTdd,
                $capaciteFdd,
                (float) ($site['capacite_mbps'] ?? 0)
            );
            $tauxUtilisation = $site['taux_utilisation'] ?? null;
            if ($tauxUtilisation === null && $capaciteTotal > 0 && $maxTrafic > 0) {
                $tauxUtilisation = $this->capacityUtilizationService->computeUtilizationRate(
                    $maxTrafic,
                    $classification,
                    $capaciteTdd,
                    $capaciteFdd,
                    $capaciteTotal
                );
            }

            $statut = $site['statut'] ?? $this->getStatut((int) ($site['nombre_occurrences'] ?? 0), (int) ($site['duree_jours'] ?? 7));
            $statutLabel = $site['statut_label'] ?? ($statut === 'SOUS_OBSERVATION' ? 'SOUS OBSERVATION' : $statut);

            $sheet->setCellValue('A' . $row, strtoupper($this->cleanSiteName((string) ($site['site_clean'] ?? $site['site'] ?? ''))));
            $sheet->setCellValue('B' . $row, $site['classification_display'] ?? $site['classification'] ?? '');
            $sheet->setCellValue('C' . $row, ($site['type_trans'] ?? 'only FDD') === '-' ? 'only FDD' : ($site['type_trans'] ?? 'only FDD'));
            $sheet->setCellValue('D' . $row, (float) ($site['max_tdd'] ?? $site['max_trafic_tdd'] ?? 0));
            $sheet->setCellValue('E' . $row, (float) ($site['max_fdd'] ?? $site['max_trafic_fdd'] ?? 0));
            $sheet->setCellValue('F' . $row, $maxTrafic);
            $sheet->setCellValue('G' . $row, $capaciteTdd > 0 ? $this->formatDecimalNoRounding($site['capacite_tdd_mbps'] ?? $capaciteTdd) : 0);
            $sheet->setCellValue('H' . $row, $capaciteFdd > 0 ? $this->formatDecimalNoRounding($site['capacite_fdd_mbps'] ?? $capaciteFdd) : 0);
            $sheet->setCellValue('I' . $row, $this->formatTauxUtilisationPourcent($tauxUtilisation !== null ? (float) $tauxUtilisation : null));
            $sheet->setCellValue('J' . $row, (int) ($site['nombre_occurrences'] ?? 0));
            $sheet->setCellValue('K' . $row, (int) ($site['nombre_occurrences_tdd'] ?? 0));
            $sheet->setCellValue('L' . $row, (int) ($site['nombre_occurrences_fdd'] ?? 0));
            $sheet->setCellValue('M' . $row, $statutLabel);
            $sheet->setCellValue('N' . $row, $site['etat'] ?? 'OK');
            $sheet->setCellValue('O' . $row, (int) ($site['dropcong_tdd'] ?? 0));
            $sheet->setCellValue('P' . $row, (int) ($site['dropcong_fdd'] ?? 0));
            $sheet->setCellValue('Q' . $row, (int) ($site['dropcong_tf'] ?? 0));
            $row++;
        }

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_map_selection_');
        $writer->save($tempFile);

        $response = new Response(file_get_contents($tempFile));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'sites_selection_carte_' . date('Y-m-d_His') . '.xlsx'));
        unlink($tempFile);

        return $response;
    }
}
