<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Résolution capacité TDD/FDD/effective (port_data + capacite_site),
 * alignée sur la logique du tableau transmission.
 */
class SiteCapacityResolver
{
    public function __construct(
        private readonly CapacityUtilizationService $capacityUtilizationService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $analyse
     *
     * @return array{
     *     capacite_tdd_mbps: float,
     *     capacite_fdd_mbps: float,
     *     capacite_effective_mbps: float,
     *     capacityTF: float,
     *     capacityLM: float,
     *     capacityTC: float,
     *     capacityPlain: float,
     *     capacityMbps: float
     * }
     */
    public function resolveForCourbe(
        Connection $conn,
        string $baseSite,
        ?array $analyse,
        ?string $siteTf,
        ?string $siteLm,
        ?string $siteTc,
        ?string $sitePlain,
    ): array {
        $classification = strtoupper((string) ($analyse['classification'] ?? ''));
        $typeTrans = $this->normalizeImportedType((string) ($analyse['type_trans'] ?? ''));
        $fallback = isset($analyse['capacite_mbps']) ? (float) $analyse['capacite_mbps'] : 0.0;
        $portMap = $this->buildPortCapacityMap($conn);

        $tdd = 0.0;
        $fdd = 0.0;
        $siteCandidates = $classification === 'TF' ? [
            $siteTf,
            $this->siteVariant($baseSite, 'TF'),
            $baseSite,
            $sitePlain,
        ] : [
            $analyse['site_tdd'] ?? null,
            $analyse['site_fdd'] ?? null,
            $baseSite,
            $siteTf,
            $siteLm,
            $siteTc,
            $sitePlain,
        ];

        $hasImportedCapacity = $this->applyImportedCapacity($conn, $portMap, $baseSite, $tdd, $fdd, $classification, $typeTrans);
        if (!$hasImportedCapacity) {
            foreach ($siteCandidates as $siteName) {
                $hasImportedCapacity = $this->applyImportedCapacity($conn, $portMap, $siteName, $tdd, $fdd, $classification, $typeTrans)
                    || $hasImportedCapacity;
            }
        }

        if (!$hasImportedCapacity) {
            foreach ($siteCandidates as $siteName) {
                $this->mergePortCapacity($portMap, $siteName, $tdd, $fdd);
            }
        }

        if ($classification === 'TF') {
            $fdd = $fdd > 0 ? $fdd : $fallback;
            $tdd = 0.0;
        }

        $effective = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
            $classification,
            $tdd,
            $fdd,
            $fallback
        );

        return [
            'capacite_tdd_mbps' => $tdd,
            'capacite_fdd_mbps' => $fdd,
            'capacite_effective_mbps' => $effective,
            'capacityTF' => $this->resolveModeCapacity($conn, $portMap, 'TF', $classification, $tdd, $fdd, $siteTf, $hasImportedCapacity, $typeTrans),
            'capacityLM' => $this->resolveModeCapacity($conn, $portMap, 'LM', $classification, $tdd, $fdd, $siteLm, $hasImportedCapacity, $typeTrans),
            'capacityTC' => $this->resolveModeCapacity($conn, $portMap, 'TC', $classification, $tdd, $fdd, $siteTc, $hasImportedCapacity, $typeTrans),
            'capacityPlain' => $this->resolveModeCapacity($conn, $portMap, 'PLAIN', $classification, $tdd, $fdd, $sitePlain ?: $baseSite, $hasImportedCapacity, $typeTrans) ?: $effective,
            'capacityMbps' => $effective,
        ];
    }

    private function resolveModeCapacity(
        Connection $conn,
        array $portMap,
        string $mode,
        string $classification,
        float $tdd,
        float $fdd,
        ?string $trafficSite,
        bool $hasImportedCapacity,
        string $typeTrans,
    ): float {
        $trafficSite = trim((string) $trafficSite);
        if ($trafficSite === '') {
            return match ($mode) {
                'LM' => $fdd,
                'TC' => $tdd,
                'TF' => $this->capacityUtilizationService->usesFddCapacityOnly($classification) ? $fdd : max($tdd, $fdd),
                default => 0.0,
            };
        }

        $modeTdd = $tdd;
        $modeFdd = $fdd;
        if (!$hasImportedCapacity) {
            $siteHasImportedCapacity = $this->applyImportedCapacity($conn, $portMap, $trafficSite, $modeTdd, $modeFdd, $classification, $typeTrans);
            if (!$siteHasImportedCapacity) {
                $this->mergePortCapacity($portMap, $trafficSite, $modeTdd, $modeFdd);
            }
        }

        return match ($mode) {
            'LM' => $modeFdd > 0 ? $modeFdd : $fdd,
            'TC' => $modeTdd > 0 ? $modeTdd : $tdd,
            'TF' => $this->capacityUtilizationService->usesFddCapacityOnly($classification)
                ? ($modeFdd > 0 ? $modeFdd : $fdd)
                : max($modeTdd, $modeFdd),
            'PLAIN' => $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
                $classification,
                $modeTdd,
                $modeFdd,
                0.0
            ),
            default => 0.0,
        };
    }

    private function applyImportedCapacity(
        Connection $conn,
        array $portMap,
        ?string $site,
        float &$tdd,
        float &$fdd,
        string $classification = '',
        string $typeTrans = '',
    ): bool {
        $site = trim((string) $site);
        if ($site === '') {
            return false;
        }

        $imported = $this->fetchCapaciteSite($conn, $site, $typeTrans);
        if ($imported['capacity'] <= 0) {
            return false;
        }

        $ports = $this->getPortCapacity($portMap, $site);

        if ($this->isTddSiteName($site)) {
            $tdd = max($tdd, $imported['capacity']);

            return true;
        }

        if ($this->isFddSiteName($site)) {
            $fdd = max($fdd, $imported['capacity']);

            return true;
        }

        if (($ports['tdd'] + $ports['fdd']) > 0) {
            if ($ports['tdd'] > 0) {
                $tdd = max($tdd, $imported['capacity']);
            }
            if ($ports['fdd'] > 0) {
                $fdd = max($fdd, $imported['capacity']);
            }

            return true;
        }

        if ($typeTrans === 'FH' || $this->capacityUtilizationService->usesFddCapacityOnly($classification)) {
            $fdd = max($fdd, $imported['capacity']);

            return true;
        }

        $tdd = max($tdd, $imported['capacity']);

        return true;
    }

    private function mergePortCapacity(
        array $portMap,
        ?string $site,
        float &$tdd,
        float &$fdd,
    ): void {
        $site = trim((string) $site);
        if ($site === '') {
            return;
        }

        $ports = $this->getPortCapacity($portMap, $site);
        $tdd = max($tdd, $ports['tdd']);
        $fdd = max($fdd, $ports['fdd']);
    }

    /**
     * @return array{tdd: float, fdd: float, date: ?string}
     */
    private function getPortCapacity(array $portMap, string $site): array
    {
        $key = strtoupper($site);
        if (isset($portMap[$key])) {
            return $portMap[$key];
        }

        $cleanKey = strtoupper($this->cleanSiteName($site));
        if ($this->isTfSiteName($site)) {
            return ['tdd' => 0.0, 'fdd' => 0.0, 'date' => null];
        }

        if ($cleanKey !== '' && isset($portMap[$cleanKey])) {
            return $portMap[$cleanKey];
        }

        return ['tdd' => 0.0, 'fdd' => 0.0, 'date' => null];
    }

    /**
     * @return array<string, array{tdd: float, fdd: float, date: ?string}>
     */
    private function buildPortCapacityMap(Connection $conn): array
    {
        if (!$this->tableExists($conn, 'port_data')) {
            return [];
        }

        $rows = $conn->fetchAllAssociative(
            'SELECT site, port_no, trafic, date_import
             FROM (
                SELECT site, port_no, trafic, date_import,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, :suffixPattern, \'\', \'i\'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
             ) latest_ports
             WHERE date_import = latest_site_date AND row_num = 1
             ORDER BY UPPER(site), port_no',
            ['suffixPattern' => $this->siteSuffixPattern()]
        );
        $capacities = [];

        foreach ($rows as $row) {
            $site = trim((string) ($row['site'] ?? ''));
            if ($site === '') {
                continue;
            }

            $siteUpper = strtoupper($site);
            if (!isset($capacities[$siteUpper])) {
                $capacities[$siteUpper] = ['tdd' => 0.0, 'fdd' => 0.0, 'date' => null];
            }

            $traffic = (float) ($row['trafic'] ?? 0);
            if ($traffic <= 0) {
                continue;
            }

            $capacities[$siteUpper]['date'] = $this->latestDate(
                $capacities[$siteUpper]['date'],
                isset($row['date_import']) ? (string) $row['date_import'] : null
            );

            if ($this->isTddSiteName($site)) {
                $capacities[$siteUpper]['tdd'] = max($capacities[$siteUpper]['tdd'], $traffic);
            } elseif ($this->isFddSiteName($site)) {
                $capacities[$siteUpper]['fdd'] = max($capacities[$siteUpper]['fdd'], $traffic);
            } elseif ((int) ($row['port_no'] ?? -1) === 0) {
                $capacities[$siteUpper]['tdd'] = max($capacities[$siteUpper]['tdd'], $traffic);
            } elseif ((int) ($row['port_no'] ?? -1) === 1) {
                $capacities[$siteUpper]['fdd'] = max($capacities[$siteUpper]['fdd'], $traffic);
            }
        }

        $result = [];
        foreach ($capacities as $site => $ports) {
            if ($this->isTfSiteName($site) && (($ports['tdd'] + $ports['fdd']) > 0)) {
                $ports['fdd'] = $ports['tdd'] + $ports['fdd'];
                $ports['tdd'] = 0.0;
            }

            if (($ports['tdd'] + $ports['fdd']) <= 0) {
                continue;
            }

            $result[$site] = $ports;
            $cleanKey = strtoupper($this->cleanSiteName($site));
            if ($cleanKey === '') {
                continue;
            }

            if (!isset($result[$cleanKey])) {
                $result[$cleanKey] = ['tdd' => 0.0, 'fdd' => 0.0, 'date' => null];
            }

            $result[$cleanKey]['tdd'] = max($result[$cleanKey]['tdd'], $ports['tdd']);
            $result[$cleanKey]['fdd'] = max($result[$cleanKey]['fdd'], $ports['fdd']);
            $result[$cleanKey]['date'] = $this->latestDate($result[$cleanKey]['date'], $ports['date']);
        }

        uksort($result, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $result;
    }

    /**
     * @return array{capacity: float, date: ?string}
     */
    private function fetchCapaciteSite(Connection $conn, ?string $site, string $typeTrans = ''): array
    {
        $site = trim((string) $site);
        if ($site === '' || !$this->tableExists($conn, 'capacite_site')) {
            return ['capacity' => 0.0, 'date' => null];
        }

        $cleanSite = $this->cleanSiteName($site);
        $rows = $conn->fetchAllAssociative(
            'SELECT type_trans, capacite_mbps, date_mise_ajour
             FROM capacite_site
             WHERE capacite_mbps > 0
               AND (UPPER(site) = UPPER(:site) OR UPPER(site) = UPPER(:clean_site))
             ORDER BY
                date_mise_ajour DESC,
                CASE WHEN UPPER(site) = UPPER(:site) THEN 0 ELSE 1 END,
                id DESC',
            ['site' => $site, 'clean_site' => $cleanSite]
        );

        if ($rows === []) {
            return ['capacity' => 0.0, 'date' => null];
        }

        $wantedType = $this->normalizeImportedType($typeTrans);
        if ($wantedType !== 'NON_DEFINI') {
            foreach ($rows as $row) {
                if ($this->normalizeImportedType((string) ($row['type_trans'] ?? '')) === $wantedType) {
                    return [
                        'capacity' => (float) ($row['capacite_mbps'] ?? 0),
                        'date' => isset($row['date_mise_ajour']) ? (string) $row['date_mise_ajour'] : null,
                    ];
                }
            }
        }

        return [
            'capacity' => (float) ($rows[0]['capacite_mbps'] ?? 0),
            'date' => isset($rows[0]['date_mise_ajour']) ? (string) $rows[0]['date_mise_ajour'] : null,
        ];
    }

    private function tableExists(Connection $conn, string $table): bool
    {
        return (bool) $conn->fetchOne(
            'SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_name = :table
            )',
            ['table' => $table]
        );
    }

    private function isTddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(TC|TD|TDD)$/i', trim((string) $site));
    }

    private function isTfSiteName(?string $site): bool
    {
        return (bool) preg_match('/_TF$/i', trim((string) $site));
    }

    private function isFddSiteName(?string $site): bool
    {
        return (bool) preg_match('/_(LM|LMB|LM2|FDD|BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$/i', trim((string) $site));
    }

    private function siteVariant(string $site, string $suffix): string
    {
        $base = $this->cleanSiteName($site);

        return ($base !== '' ? $base : trim($site)) . '_' . $suffix;
    }

    private function latestDate(?string $current, ?string $candidate): ?string
    {
        if (!$current) {
            return $candidate ?: null;
        }

        if (!$candidate) {
            return $current;
        }

        return $this->isNewerDate($candidate, $current) ? $candidate : $current;
    }

    private function isNewerDate(?string $candidate, ?string $reference): bool
    {
        if (!$candidate) {
            return false;
        }

        if (!$reference) {
            return true;
        }

        try {
            return new \DateTimeImmutable($candidate) > new \DateTimeImmutable($reference);
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeImportedType(string $type): string
    {
        $type = strtoupper(trim($type));
        $type = str_replace([' ', '-', '/', '+'], '_', $type);
        $type = preg_replace('/_+/', '_', $type) ?? $type;

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

    private function cleanSiteName(string $site): string
    {
        $cleaned = trim($site);
        $previous = null;

        while ($cleaned !== $previous) {
            $previous = $cleaned;
            $cleaned = preg_replace('/_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$/i', '', $cleaned) ?? $cleaned;
            $cleaned = preg_replace('/_(BH|BO|TR|OO|OR|TT)(?:_[A-Z0-9]+)*$/i', '', $cleaned) ?? $cleaned;
            $cleaned = trim((string) $cleaned);
        }

        return $cleaned;
    }

    private function siteSuffixPattern(): string
    {
        return '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$';
    }
}
