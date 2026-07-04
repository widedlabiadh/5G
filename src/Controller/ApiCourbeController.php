<?php
// src/Controller/ApiCourbeController.php

namespace App\Controller;

use App\Service\SiteCapacityResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiCourbeController extends AbstractController
{
    public function __construct(
        private readonly SiteCapacityResolver $siteCapacityResolver,
    ) {
    }
    #[Route('/api/sites/liste', name: 'api_sites_liste', methods: ['GET'])]
    public function listeSites(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $sites = $conn->fetchFirstColumn(
            'SELECT site
             FROM (
                SELECT DISTINCT site
                FROM trafic_historique
                WHERE site IS NOT NULL AND TRIM(site) <> \'\'
                UNION
                SELECT DISTINCT site
                FROM analyse_resultat
                WHERE site IS NOT NULL AND TRIM(site) <> \'\'
             ) AS all_sites
             ORDER BY site'
        );

        return $this->json($sites);
    }

    #[Route('/api/trafic/date-range', name: 'api_trafic_date_range', methods: ['GET'])]
    public function dateRange(EntityManagerInterface $em): JsonResponse
    {
        $range = $this->getTrafficDateRange($em->getConnection());

        return $this->json([
            'start' => $range['start']?->format('Y-m-d'),
            'end' => $range['end']?->format('Y-m-d'),
            'points' => $range['points'],
        ]);
    }

    #[Route('/api/site/{baseSite}/trafic-date-range', name: 'api_site_trafic_date_range', methods: ['GET'])]
    public function siteTrafficDateRange(string $baseSite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $siteNames = array_filter(array_map('trim', explode(',', (string) $request->query->get('siteNames', ''))));
        $siteName = $request->query->get('siteName');

        if ($siteName) {
            $siteNames[] = (string) $siteName;
        }

        if ($siteNames === []) {
            $type = $request->query->get('type');
            $types = json_decode($this->getSiteTypes($baseSite, $em)->getContent(), true);
            $resolved = match ($type) {
                'TF' => $types['siteTF'] ?? null,
                'LM' => $types['siteLM'] ?? null,
                'TC' => $types['siteTC'] ?? null,
                default => $types['sitePlain'] ?? $baseSite,
            };
            if ($resolved) {
                $siteNames[] = (string) $resolved;
            }
        }

        $latestImportOnly = $request->query->getBoolean('latest_import');
        $range = $this->getTrafficDateRangeForSites($conn, $siteNames, $latestImportOnly);

        return $this->json([
            'start' => $range['start']?->format('Y-m-d'),
            'end' => $range['end']?->format('Y-m-d'),
            'points' => $range['points'],
            'dateImportation' => $range['dateImportation'],
        ]);
    }

    #[Route('/api/site/{baseSite}/types', name: 'api_site_types', methods: ['GET'])]
    public function getSiteTypes(string $baseSite, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $analyse = $this->getLatestAnalyseForSite($conn, $baseSite);

        if ($analyse === null) {
            return $this->json($this->buildFallbackTypes($conn, $baseSite));
        }

        $classification = strtoupper((string) $analyse['classification']);
        $siteTf = null;
        $siteLm = null;
        $siteTc = null;
        $isPlain = false;

        if ($classification === 'TF') {
            $siteTf = $this->findTrafficSite($conn, $baseSite, ['TF']);
        } elseif (in_array($classification, ['COTRANS', 'NO_COTRANS'], true)) {
            $siteLm = $this->existingTrafficSite($conn, $analyse['site_fdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['LM', 'LMB', 'LM2', 'FDD']);
            $siteTc = $this->existingTrafficSite($conn, $analyse['site_tdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['TC', 'TD', 'TDD']);
        } elseif (in_array($classification, ['FDD', 'ONLY_FDD'], true)) {
            $siteLm = $this->existingTrafficSite($conn, $analyse['site_fdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['LM', 'LMB', 'LM2', 'FDD']);
        } else {
            $isPlain = true;
        }

        if ($isPlain) {
            $sitePlain = $this->existingTrafficSite($conn, $baseSite) ?? $this->findTrafficSite($conn, $baseSite, []);
        } else {
            $sitePlain = null;
        }

        $capacities = $this->siteCapacityResolver->resolveForCourbe(
            $conn,
            $baseSite,
            $analyse,
            $siteTf,
            $siteLm,
            $siteTc,
            $sitePlain
        );

        return $this->json([
            'classification' => $classification,
            'hasTF' => $siteTf !== null,
            'hasLM' => $siteLm !== null,
            'hasTC' => $siteTc !== null,
            'isPlain' => $isPlain,
            'siteTF' => $siteTf,
            'siteLM' => $siteLm,
            'siteTC' => $siteTc,
            'sitePlain' => $sitePlain,
            'capacityMbps' => $capacities['capacityMbps'],
            'capacityTF' => $capacities['capacityTF'],
            'capacityLM' => $capacities['capacityLM'],
            'capacityTC' => $capacities['capacityTC'],
            'capacityPlain' => $capacities['capacityPlain'],
            'capaciteTddMbps' => $capacities['capacite_tdd_mbps'],
            'capaciteFddMbps' => $capacities['capacite_fdd_mbps'],
            'defaultMode' => $this->getDefaultMode($classification, $siteTf, $siteLm, $siteTc, $isPlain),
        ]);
    }

    #[Route('/api/site/{baseSite}/trafic', name: 'api_site_trafic', methods: ['GET'])]
    public function getTrafic(string $baseSite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $type = $request->query->get('type');
        $siteName = $request->query->get('siteName');

        if (!$siteName) {
            $types = json_decode($this->getSiteTypes($baseSite, $em)->getContent(), true);
            $siteName = match ($type) {
                'TF' => $types['siteTF'] ?? null,
                'LM' => $types['siteLM'] ?? null,
                'TC' => $types['siteTC'] ?? null,
                default => $types['sitePlain'] ?? $baseSite,
            };
        }

        return $this->json($this->fetchTrafficRows($em->getConnection(), (string) $siteName, $request));
    }

    #[Route('/api/site/{baseSite}/both-trafic', name: 'api_site_both_trafic', methods: ['GET'])]
    public function getBothTrafic(string $baseSite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $types = json_decode($this->getSiteTypes($baseSite, $em)->getContent(), true);
        $siteLm = $request->query->get('siteLM') ?: ($types['siteLM'] ?? null);
        $siteTc = $request->query->get('siteTC') ?: ($types['siteTC'] ?? null);

        return $this->json([
            'LM' => [
                'label' => $siteLm ?: $baseSite . '_LM',
                'data' => $siteLm ? $this->fetchTrafficRows($conn, $siteLm, $request) : [],
            ],
            'TC' => [
                'label' => $siteTc ?: $baseSite . '_TC',
                'data' => $siteTc ? $this->fetchTrafficRows($conn, $siteTc, $request) : [],
            ],
        ]);
    }

    private function getLastAnalyseDate(Connection $conn): ?string
    {
        $date = $conn->fetchOne('SELECT MAX(date_analyse) FROM analyse_resultat');

        return $date ? (string) $date : null;
    }

    private function getLatestAnalyseForSite(Connection $conn, string $site): ?array
    {
        $lastDate = $this->getLastAnalyseDate($conn);
        if ($lastDate === null) {
            return null;
        }

        $row = $conn->fetchAssociative(
            'SELECT site, classification, site_fdd, site_tdd, capacite_mbps, type_trans
             FROM analyse_resultat
             WHERE date_analyse = :date AND UPPER(site) = UPPER(:site)
             LIMIT 1',
            ['date' => $lastDate, 'site' => $site]
        );

        return $row ?: null;
    }

    private function buildFallbackTypes(Connection $conn, string $site): array
    {
        $siteTf = $this->findTrafficSite($conn, $site, ['TF']);
        $siteLm = $this->findTrafficSite($conn, $site, ['LM', 'LMB', 'LM2', 'FDD']);
        $siteTc = $this->findTrafficSite($conn, $site, ['TC', 'TD', 'TDD']);
        $sitePlain = $this->existingTrafficSite($conn, $site);

        $capacities = $this->siteCapacityResolver->resolveForCourbe(
            $conn,
            $site,
            null,
            $siteTf,
            $siteLm,
            $siteTc,
            $sitePlain
        );

        return [
            'classification' => $siteTf ? 'TF' : 'PLAIN',
            'hasTF' => $siteTf !== null,
            'hasLM' => $siteLm !== null,
            'hasTC' => $siteTc !== null,
            'isPlain' => $sitePlain !== null && !$siteTf && !$siteLm && !$siteTc,
            'siteTF' => $siteTf,
            'siteLM' => $siteLm,
            'siteTC' => $siteTc,
            'sitePlain' => $sitePlain,
            'capacityMbps' => $capacities['capacityMbps'],
            'capacityTF' => $capacities['capacityTF'],
            'capacityLM' => $capacities['capacityLM'],
            'capacityTC' => $capacities['capacityTC'],
            'capacityPlain' => $capacities['capacityPlain'],
            'capaciteTddMbps' => $capacities['capacite_tdd_mbps'],
            'capaciteFddMbps' => $capacities['capacite_fdd_mbps'],
            'defaultMode' => $siteTf ? 'TF' : ($sitePlain ? null : ($siteLm ? 'LM' : ($siteTc ? 'TC' : null))),
        ];
    }

    private function getDefaultMode(string $classification, ?string $siteTf, ?string $siteLm, ?string $siteTc, bool $isPlain): ?string
    {
        if ($isPlain) {
            return null;
        }

        if ($classification === 'TF' && $siteTf !== null) {
            return 'TF';
        }

        if ($siteLm !== null) {
            return 'LM';
        }

        if ($siteTc !== null) {
            return 'TC';
        }

        return null;
    }

    private function existingTrafficSite(Connection $conn, ?string $site): ?string
    {
        if (!$site) {
            return null;
        }

        $row = $conn->fetchOne(
            'SELECT site FROM trafic_historique WHERE UPPER(site) = UPPER(:site) LIMIT 1',
            ['site' => $site]
        );

        return $row ? (string) $row : null;
    }

    private function findTrafficSite(Connection $conn, string $baseSite, array $suffixes): ?string
    {
        if ($suffixes === []) {
            return $this->existingTrafficSite($conn, $baseSite);
        }

        foreach ($suffixes as $suffix) {
            $site = $this->existingTrafficSite($conn, $baseSite . '_' . $suffix);
            if ($site !== null) {
                return $site;
            }
        }

        $rows = $conn->fetchFirstColumn(
            'SELECT DISTINCT site FROM trafic_historique WHERE UPPER(site) LIKE UPPER(:prefix) ORDER BY site',
            ['prefix' => $baseSite . '_%']
        );

        foreach ($rows as $row) {
            foreach ($suffixes as $suffix) {
                if (str_starts_with(strtoupper((string) $row), strtoupper($baseSite . '_' . $suffix))) {
                    return (string) $row;
                }
            }
        }

        return null;
    }

    private function fetchTrafficRows(Connection $conn, string $siteName, Request $request): array
    {
        $startParam = $request->query->get('start') ?: $request->query->get('date_debut');
        $endParam = $request->query->get('end') ?: $request->query->get('date_fin');
        $range = $this->getTrafficDateRange($conn, $siteName);
        $fallbackStart = $range['start']?->format('Y-m-d') ?? '1970-01-01';
        $fallbackEnd = $range['end']?->format('Y-m-d') ?? '2999-12-31';
        $start = $this->parseDateParam($startParam, $fallbackStart)->setTime(0, 0, 0);
        $end = $this->parseDateParam($endParam, $fallbackEnd)->setTime(23, 59, 59);

        if ($range['start'] instanceof \DateTimeInterface && $start < $range['start']) {
            $start = \DateTime::createFromInterface($range['start']);
        }
        if ($range['end'] instanceof \DateTimeInterface && $end > $range['end']) {
            $end = \DateTime::createFromInterface($range['end']);
        }

        if ($start > $end) {
            return [];
        }

        $rows = $conn->fetchAllAssociative(
            "WITH hourly_axis AS (
                SELECT generate_series(
                    date_trunc('hour', CAST(:start AS timestamp)),
                    date_trunc('hour', CAST(:end AS timestamp)),
                    interval '1 hour'
                ) AS date_heure
             ),
             hourly_traffic AS (
                SELECT
                    date_trunc('hour', date_heure) AS date_heure,
                    MAX(max_speed) AS max_speed
                FROM trafic_historique
                WHERE site = :site
                  AND date_heure >= :start
                  AND date_heure <= :end
                GROUP BY date_trunc('hour', date_heure)
             )
             SELECT
                hourly_axis.date_heure,
                COALESCE(hourly_traffic.max_speed, 0) AS max_speed
             FROM hourly_axis
             LEFT JOIN hourly_traffic ON hourly_traffic.date_heure = hourly_axis.date_heure
             ORDER BY hourly_axis.date_heure ASC",
            [
                'site' => $siteName,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'time' => new \DateTime($row['date_heure']),
                'speed' => (float) $row['max_speed'],
            ];
        }

        return array_map(static function (array $point): array {
            return [
                'datetime' => $point['time']->format('Y-m-d\TH:i:s'),
                'timestamp' => ((int) $point['time']->format('U')) * 1000,
                'max_speed' => $point['speed'],
            ];
        }, $points);
    }

    private function getTrafficDateRange(Connection $conn, ?string $siteName = null): array
    {
        $where = '';
        $params = [];

        if ($siteName !== null && trim($siteName) !== '') {
            $where = ' WHERE site = :site';
            $params['site'] = $siteName;
        }

        $row = $conn->fetchAssociative(
            'SELECT MIN(date_heure) AS start_date, MAX(date_heure) AS end_date, COUNT(*) AS points
             FROM trafic_historique' . $where,
            $params
        );

        return [
            'start' => !empty($row['start_date']) ? new \DateTime((string) $row['start_date']) : null,
            'end' => !empty($row['end_date']) ? new \DateTime((string) $row['end_date']) : null,
            'points' => (int) ($row['points'] ?? 0),
        ];
    }

    /**
     * @param string[] $siteNames
     */
    private function getTrafficDateRangeForSites(Connection $conn, array $siteNames, bool $latestImportOnly = false): array
    {
        $siteNames = array_values(array_unique(array_filter($siteNames, static fn (string $site): bool => $site !== '')));
        if ($siteNames === []) {
            return [
                'start' => null,
                'end' => null,
                'points' => 0,
                'dateImportation' => null,
            ];
        }

        if ($latestImportOnly) {
            $row = $conn->fetchAssociative(
                'WITH latest_import AS (
                    SELECT MAX(date_importation) AS date_importation
                    FROM trafic_historique
                    WHERE site IN (:sites)
                )
                SELECT
                    MIN(th.date_heure) AS start_date,
                    MAX(th.date_heure) AS end_date,
                    COUNT(*) AS points,
                    latest_import.date_importation
                FROM latest_import
                LEFT JOIN trafic_historique th
                    ON th.date_importation = latest_import.date_importation
                   AND th.site IN (:sites)
                GROUP BY latest_import.date_importation',
                ['sites' => $siteNames],
                ['sites' => ArrayParameterType::STRING]
            );

            return [
                'start' => !empty($row['start_date']) ? new \DateTime((string) $row['start_date']) : null,
                'end' => !empty($row['end_date']) ? new \DateTime((string) $row['end_date']) : null,
                'points' => (int) ($row['points'] ?? 0),
                'dateImportation' => $row['date_importation'] ?? null,
            ];
        }

        $row = $conn->fetchAssociative(
            'SELECT MIN(date_heure) AS start_date, MAX(date_heure) AS end_date, COUNT(*) AS points, MAX(date_importation) AS date_importation
             FROM trafic_historique
             WHERE site IN (:sites)',
            ['sites' => $siteNames],
            ['sites' => ArrayParameterType::STRING]
        );

        return [
            'start' => !empty($row['start_date']) ? new \DateTime((string) $row['start_date']) : null,
            'end' => !empty($row['end_date']) ? new \DateTime((string) $row['end_date']) : null,
            'points' => (int) ($row['points'] ?? 0),
            'dateImportation' => $row['date_importation'] ?? null,
        ];
    }

    private function parseDateParam(?string $value, string $fallback): \DateTime
    {
        if (!$value) {
            return new \DateTime($fallback);
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date ?: new \DateTime($fallback);
    }

}
