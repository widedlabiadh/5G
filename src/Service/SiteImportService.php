<?php

namespace App\Service;

use App\Entity\ManagedSite;
use App\Repository\ManagedSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SiteImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagedSiteRepository $repository,
    ) {
    }

    /**
     * @return array{created: int, updated: int, ignored: int, intent: string}
     */
    public function importFromExcel(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        // Lire toutes les cellules en un seul appel (évite N×M appels getCell)
        $sheetData = $sheet->toArray(null, false, false);

        [$headerRow, $headerMap] = $this->detectExcelHeaderFromData($sheetData, $highestColumnIndex, min($highestRow, 10));

        if (!in_array('site', $headerMap, true)) {
            throw new \RuntimeException('La colonne "Site" ou "LOT" est obligatoire.');
        }

        $intent = 'default';
        if (
            (in_array('latitude', $headerMap, true) || in_array('longitude', $headerMap, true))
            && !$this->hasManualSiteColumns($headerMap)
        ) {
            $intent = 'update_gps';
        }

        // Passe 1 : parser toutes les lignes et collecter les noms de sites
        $parsedRows = [];
        $ignored = 0;
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $rowData = $sheetData[$row] ?? [];
            $values = [];
            $rawRow = [];
            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $cellValue = $rowData[$column - 1] ?? null;
                $rawRow[$column] = $cellValue;
                $key = $headerMap[$column] ?? null;
                if ($key !== null) {
                    $values[$key] = $cellValue;
                }
            }
            $siteName = $this->resolveSiteName($values, $rawRow);
            if ($siteName === '') {
                $ignored++;
                continue;
            }
            $parsedRows[] = ['siteName' => $siteName, 'values' => $values];
        }

        // Chargement bulk en UNE SEULE requête SQL (au lieu de 1 requête par site)
        $uniqueNames = array_unique(array_column($parsedRows, 'siteName'));
        $managedSites = $this->repository->findByNamesInsensitiveIndexed($uniqueNames);

        $created = 0;
        $updated = 0;

        // Passe 2 : créer/mettre à jour les entités sans accès DB supplémentaire
        foreach ($parsedRows as $parsed) {
            $siteName = $parsed['siteName'];
            $key = mb_strtolower($siteName);

            if (!isset($managedSites[$key])) {
                $site = new ManagedSite();
                $site->setName($siteName);
                $this->em->persist($site);
                $managedSites[$key] = $site;
                $created++;
            } else {
                $updated++;
            }

            $site = $managedSites[$key];
            if ($intent === 'update_gps') {
                $this->applyGpsUpdate($site, $parsed['values']);
            } else {
                $this->applyImportedValues($site, $parsed['values']);
            }
        }

        $this->em->flush();

        // Propager classification/capacite/GPS vers analyse_resultat en UNE SEULE requête batch
        $this->batchSyncToAnalyse($managedSites);

        return [
            'created' => $created,
            'updated' => $updated,
            'ignored' => $ignored,
            'intent' => $intent,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $sheetData
     * @return array{0: int, 1: array<int, string>}
     */
    private function detectExcelHeaderFromData(array $sheetData, int $highestColumnIndex, int $maxRows): array
    {
        foreach ($sheetData as $row => $rowData) {
            if ($row > $maxRows) {
                break;
            }
            $headers = [];
            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $normalized = $this->normalizeHeader((string) ($rowData[$column - 1] ?? ''));
                if ($normalized !== null) {
                    $headers[$column] = $normalized;
                }
            }
            if (in_array('site', $headers, true)) {
                return [$row, $headers];
            }
        }

        return [1, []];
    }

    private function normalizeHeader(string $header): ?string
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
        $header = strtolower($ascii ?: $header);
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header) ?: $header;
        $header = trim(preg_replace('/\s+/', ' ', $header) ?: $header);

        return match ($header) {
            'site', 'nom site', 'nom du site', 'sites', 'lot' => 'site',
            'classification', 'site classification' => 'classification',
            'type', 'type liaison', 'type de liaison', 'type trans' => 'type',
            'max tdd' => 'max_tdd',
            'max fdd' => 'max_fdd',
            'trafic max', 'max trafic', 'trafic max mbps', 'max traffic' => 'trafic_max',
            'capacite tdd', 'capacite tdd mbps' => 'capacite_tdd',
            'capacite fdd', 'capacite fdd mbps', 'capacite fh' => 'capacite_fdd',
            'taux utilisation', 'taux util', 'utilisation' => 'taux_utilisation',
            'occurrences', 'occurrence', 'nb occurrences' => 'occurrences',
            'occurrence tdd', 'occurrences tdd', 'nb occurrences tdd' => 'occurrences_tdd',
            'occurrence fdd', 'occurrences fdd', 'nb occurrences fdd' => 'occurrences_fdd',
            'statut', 'decision' => 'statut',
            'etat' => 'etat',
            'date max', 'date maximum' => 'date_max',
            'latitude', 'lat' => 'latitude',
            'longitude', 'long', 'lon' => 'longitude',
            'notes', 'note', 'description', 'remarques', 'commentaire', 'commentaires' => 'description',
            default => null,
        };
    }

    /**
     * @param array<int, string> $headerMap
     */
    private function hasManualSiteColumns(array $headerMap): bool
    {
        return [] !== array_intersect($headerMap, [
            'type',
            'max_tdd',
            'max_fdd',
            'trafic_max',
            'capacite_tdd',
            'capacite_fdd',
            'taux_utilisation',
            'occurrences',
            'occurrences_tdd',
            'occurrences_fdd',
            'statut',
            'etat',
            'date_max',
            'description',
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, mixed> $rawRow
     */
    private function resolveSiteName(array $values, array $rawRow): string
    {
        foreach ([$values['site'] ?? null, ...array_values($values), ...array_values($rawRow)] as $candidate) {
            $candidate = strtoupper(trim((string) $candidate));
            if ($candidate === '') {
                continue;
            }

            if (preg_match('/\b([A-Z]{2,5}_\d{3,5})(?:_[A-Z0-9]+)*\b/', $candidate, $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function applyImportedValues(ManagedSite $site, array $values): void
    {
        if (array_key_exists('classification', $values)) {
            $site->setClassification($this->cleanText($values['classification']));
        }
        if (array_key_exists('type', $values)) {
            $site->setTypeTrans($this->cleanText($values['type']));
        }
        if (array_key_exists('max_tdd', $values)) {
            $site->setMaxTdd($this->parseDecimal($values['max_tdd']));
        }
        if (array_key_exists('max_fdd', $values)) {
            $site->setMaxFdd($this->parseDecimal($values['max_fdd']));
        }
        if (array_key_exists('trafic_max', $values)) {
            $site->setMaxTrafic($this->parseDecimal($values['trafic_max']));
        }
        if (array_key_exists('capacite_tdd', $values)) {
            $site->setCapaciteTddMbps($this->parseDecimal($values['capacite_tdd']));
        }
        if (array_key_exists('capacite_fdd', $values)) {
            $site->setCapaciteFddMbps($this->parseDecimal($values['capacite_fdd']));
        }
        if (array_key_exists('taux_utilisation', $values)) {
            $site->setTauxUtilisation($this->parseDecimal($values['taux_utilisation']));
        }
        if (array_key_exists('occurrences', $values)) {
            $site->setNombreOccurrences($this->parseInteger($values['occurrences']));
        }
        if (array_key_exists('occurrences_tdd', $values)) {
            $site->setNombreOccurrencesTdd($this->parseInteger($values['occurrences_tdd']));
        }
        if (array_key_exists('occurrences_fdd', $values)) {
            $site->setNombreOccurrencesFdd($this->parseInteger($values['occurrences_fdd']));
        }
        if (array_key_exists('statut', $values)) {
            $site->setDecisionStatut($this->cleanText($values['statut']));
        }
        if (array_key_exists('etat', $values)) {
            $site->setEtat($this->cleanText($values['etat']));
        }
        if (array_key_exists('date_max', $values)) {
            $site->setDateMax($this->parseDate($values['date_max']));
        }
        if (array_key_exists('latitude', $values)) {
            $site->setLatitude($this->parseFloat($values['latitude']));
        }
        if (array_key_exists('longitude', $values)) {
            $site->setLongitude($this->parseFloat($values['longitude']));
        }
        if (array_key_exists('description', $values)) {
            $site->setDescription($this->cleanText($values['description']));
        }
    }


    /**
     * Spécifique à la mise à jour GPS
     * @param array<string, mixed> $values
     */
    private function applyGpsUpdate(ManagedSite $site, array $values): void
    {
        if (isset($values['latitude'])) {
            $site->setLatitude((float) str_replace(',', '.', (string) $values['latitude']));
        }
        if (isset($values['longitude'])) {
            $site->setLongitude((float) str_replace(',', '.', (string) $values['longitude']));
        }
        if (isset($values['classification'])) {
            $site->setClassification($this->cleanText($values['classification']));
        }
    }

    private function cleanText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }

    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $value = (string) $value;

            return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '-' || $value === '—') {
            return null;
        }

        // Nettoyage intelligent (ex: "815M" -> 815, "500M action..." -> 500).
        if (preg_match('/^(\d[\d\s\x{00A0}]*(?:[.,]\d+)?)/u', $value, $matches)) {
            $clean = str_replace(["\xc2\xa0", ' '], '', $matches[1]);
            $clean = str_replace(',', '.', $clean);

            return rtrim(rtrim($clean, '0'), '.');
        }

        $value = str_replace(["\xc2\xa0", ' ', '%'], '', $value);
        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return null;
        }

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * Synchronise tous les ManagedSite vers analyse_resultat en UNE SEULE requête batch
     * au lieu de N requêtes individuelles (une par site).
     *
     * @param array<string, ManagedSite> $managedSites indexés par nom en minuscules
     */
    private function batchSyncToAnalyse(array $managedSites): void
    {
        $conn = $this->em->getConnection();
        $suffix = $this->siteSuffixPattern();

        $rows = [];
        foreach ($managedSites as $site) {
            $siteName = strtoupper(trim((string) $site->getName()));
            if ($siteName === '') {
                continue;
            }
            $capTdd = (float) ($site->getCapaciteTddMbps() ?? 0);
            $capFdd = (float) ($site->getCapaciteFddMbps() ?? 0);
            $rows[] = [
                'nm'   => $siteName,
                'cls'  => $site->getClassification() ?? '',
                'tp'   => $site->getTypeTrans() ?? '',
                'lat'  => $site->getLatitude(),
                'lng'  => $site->getLongitude(),
                'cap'  => $capTdd + $capFdd > 0 ? max($capTdd, $capFdd) : null,
            ];
        }

        if ($rows === []) {
            return;
        }

        $lastDate = $conn->fetchOne(
            'SELECT date_analyse FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
        ) ?: date('Y-m-d H:i:s');

        foreach (array_chunk($rows, 200) as $chunk) {
            $valueParts = [];
            $params = ['suffix' => $suffix, 'last_date' => $lastDate];

            foreach ($chunk as $i => $row) {
                $valueParts[] = "(:nm{$i}::TEXT, :cls{$i}::TEXT, :tp{$i}::TEXT, :lat{$i}::FLOAT8, :lng{$i}::FLOAT8, :cap{$i}::NUMERIC)";
                $params["nm{$i}"]  = $row['nm'];
                $params["cls{$i}"] = $row['cls'];
                $params["tp{$i}"]  = $row['tp'];
                $params["lat{$i}"] = $row['lat'];
                $params["lng{$i}"] = $row['lng'];
                $params["cap{$i}"] = $row['cap'];
            }

            $valuesSQL = implode(', ', $valueParts);

            // 1. Mettre à jour les sites qui existent déjà dans analyse_resultat
            $conn->executeStatement(
                sprintf(
                    'UPDATE analyse_resultat AS ar
                     SET classification = CASE WHEN NULLIF(u.cls, \'\') IS NOT NULL THEN u.cls ELSE ar.classification END,
                         type_trans     = CASE WHEN NULLIF(u.tp, \'\') IS NOT NULL AND ar.type_trans IS NULL THEN u.tp ELSE ar.type_trans END,
                         latitude       = COALESCE(u.lat, ar.latitude),
                         longitude      = COALESCE(u.lng, ar.longitude),
                         capacite_mbps  = COALESCE(u.cap, ar.capacite_mbps)
                     FROM (VALUES %s) AS u(nm, cls, tp, lat, lng, cap)
                     WHERE UPPER(ar.site) = u.nm
                        OR UPPER(ar.site_fdd) = u.nm
                        OR UPPER(ar.site_tdd) = u.nm
                        OR UPPER(REGEXP_REPLACE(ar.site, :suffix, \'\', \'i\')) = u.nm',
                    $valuesSQL
                ),
                $params
            );

            // 2. Insérer les nouveaux sites absents de analyse_resultat
            // Utilise date_analyse = dernière date connue pour qu'ils apparaissent immédiatement
            $conn->executeStatement(
                sprintf(
                    'INSERT INTO analyse_resultat
                        (site, classification, type_trans, max_trafic, seuil_critique,
                         nombre_occurrences, nombre_occurrences_tdd, nombre_occurrences_fdd,
                         total_measures, date_analyse, duree_jours,
                         latitude, longitude, capacite_mbps, drop_cong_kpi)
                     SELECT u.nm,
                            COALESCE(NULLIF(u.cls, \'\'), \'\'),
                            NULLIF(u.tp, \'\'),
                            0, 0, 0, 0, 0, 0,
                            :last_date::TIMESTAMP, 7,
                            u.lat, u.lng, u.cap, false
                     FROM (VALUES %s) AS u(nm, cls, tp, lat, lng, cap)
                     WHERE NOT EXISTS (
                         SELECT 1 FROM analyse_resultat ar2
                         WHERE UPPER(ar2.site) = u.nm
                            OR UPPER(ar2.site_fdd) = u.nm
                            OR UPPER(ar2.site_tdd) = u.nm
                            OR UPPER(REGEXP_REPLACE(ar2.site, :suffix, \'\', \'i\')) = u.nm
                     )',
                    $valuesSQL
                ),
                $params
            );
        }
    }

    private function siteSuffixPattern(): string
    {
        return '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$';
    }

    private function parseInteger(mixed $value): int
    {
        $decimal = $this->parseDecimal($value);

        return $decimal === null ? 0 : max(0, (int) round((float) $decimal));
    }

    private function parseDate(mixed $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '-' || $value === '—') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTimeInterface) {
                return $date;
            }
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseFloat(mixed $value): ?float
    {
        $decimal = $this->parseDecimal($value);

        return $decimal === null ? null : (float) $decimal;
    }
}
