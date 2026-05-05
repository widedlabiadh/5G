<?php

namespace App\Controller;

use App\Service\StatisticsService;
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
    private StatisticsService $statsService;

    public function __construct(StatisticsService $statsService)
    {
        $this->statsService = $statsService;
    }

    private function cleanSiteName(string $site): string
    {
        $suffixes = ['_LM', '_LMB', '_LM2', '_TC', '_TD', '_TDD', '_TF', '_BO', '_BH', '_FDD'];
        
        foreach ($suffixes as $suffix) {
            if (str_ends_with(strtoupper($site), strtoupper($suffix))) {
                $site = substr($site, 0, -strlen($suffix));
                break;
            }
        }
        
        return trim($site);
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
                'max_tdd' => null
            ];
        }
        
        if (isset($statsJson[$dureeKey])) {
            return [
                'max_trafic' => (float) ($statsJson[$dureeKey]['max_trafic'] ?? 0),
                'nombre_occurrences' => (int) ($statsJson[$dureeKey]['occurrences'] ?? 0),
                'seuil_critique' => (float) ($statsJson[$dureeKey]['seuil_critique'] ?? 0),
                'date_max' => $statsJson[$dureeKey]['date_max'] ?? null,
                'max_fdd' => (float) ($statsJson[$dureeKey]['max_fdd'] ?? 0),
                'max_tdd' => (float) ($statsJson[$dureeKey]['max_tdd'] ?? 0)
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
                'max_tdd' => (float) ($statsJson[$closestKey]['max_tdd'] ?? 0)
            ];
        }
        
        return [
            'max_trafic' => null,
            'nombre_occurrences' => null,
            'seuil_critique' => null,
            'date_max' => null,
            'max_fdd' => null,
            'max_tdd' => null
        ];
    }

    /**
     * Construit la requête SQL de base avec toutes les colonnes
     */
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
            $sql .= " AND a.classification = :class";
            $params['class'] = $classFilter;
        }
        
        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Post-traitement : applique les statistiques selon la période
     */
    private function postProcessResults(array $results, ?string $dateDebut, ?string $dateFin): array
    {
        if (!$dateDebut || !$dateFin) {
            // Sans filtre date, utiliser les colonnes BDD max_trafic_fdd, max_trafic_tdd et capacite_mbps
            foreach ($results as &$row) {
                $row['max_tdd'] = (float)($row['max_trafic_tdd'] ?? 0);
                $row['max_fdd'] = (float)($row['max_trafic_fdd'] ?? 0);
                // S'assurer que la capacité est bien un float
                $row['capacite_mbps'] = (float)($row['capacite_mbps'] ?? 0);
            }
            return $results;
        }
        
        // Avec filtre date, utiliser stats_json
        $duree = $this->calculerDureeEnJours($dateDebut, $dateFin);
        $dureeKey = (string)$duree;
        
        foreach ($results as &$row) {
            $statsJson = json_decode($row['stats_json'] ?? '{}', true);
            $stats = $this->extractStatsForDuree($statsJson, $dureeKey);
            
            if ($stats['max_trafic'] !== null) {
                $row['max_trafic'] = $stats['max_trafic'];
                $row['nombre_occurrences'] = $stats['nombre_occurrences'];
                $row['seuil_critique'] = $stats['seuil_critique'];
                $row['date_max'] = $stats['date_max'];
                $row['max_tdd'] = $stats['max_tdd'];
                $row['max_fdd'] = $stats['max_fdd'];
            } else {
                $row['max_tdd'] = (float)($row['max_trafic_tdd'] ?? 0);
                $row['max_fdd'] = (float)($row['max_trafic_fdd'] ?? 0);
            }
            // Garder la capacité stockée
            $row['capacite_mbps'] = (float)($row['capacite_mbps'] ?? 0);
        }
        
        return $results;
    }

    /**
     * Détermine l'état d'un site (CONGESTION / BRIDAGE / OK)
     * Basé sur le trafic max et la capacité
     */
    private function getEtatSite(float $maxTrafic, float $capaciteMbps, int $occurrences): string
    {
        if ($capaciteMbps <= 0 || $maxTrafic <= 0) {
            return 'OK';
        }
        
        $taux = ($maxTrafic / $capaciteMbps) * 100;
        
        // Congestion : trafic >= 85% de la capacité
        if ($taux >= 85) {
            return 'CONGESTION';
        }
        
        // Bridage : occurrences >= 20 ET trafic < 85% 
        if ($occurrences > 20 && $taux < 85) {
            return 'BRIDAGE';
        }
        
        // Si on a beaucoup d'occurrences mais faible trafic => bridage
        if ($occurrences > 30 && $taux < 70) {
            return 'BRIDAGE';
        }
        
        return 'OK';
    }

    private function getSitesByTypeWithDateRange(EntityManagerInterface $em, string $type, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc'): array
    {
        $conn = $em->getConnection();
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) return [];

        $baseQuery = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                a.capacite_mbps,
                COALESCE(c.capacite_mbps, c2.capacite_mbps) as capacite_mbps_from_join,
                a.stats_json
            FROM analyse_resultat a
            LEFT JOIN capacite_site c ON (a.site LIKE c.site || \'%\') AND c.type_trans = :type
            LEFT JOIN capacite_site c2 ON (a.site_fdd LIKE c2.site || \'%\') AND c2.type_trans = :type
            WHERE UPPER(a.type_trans) = :type AND a.date_analyse = :lastDate
        ';
        
        $params = ['type' => strtoupper($type), 'lastDate' => $lastDate];
        
        $results = $this->buildSelectQuery($em, $baseQuery, $params, $search, $classFilter);
        
        // Priorité à la capacité stockée dans analyse_resultat
        foreach ($results as &$row) {
            if (($row['capacite_mbps'] ?? 0) <= 0 && ($row['capacite_mbps_from_join'] ?? 0) > 0) {
                $row['capacite_mbps'] = $row['capacite_mbps_from_join'];
            }
            unset($row['capacite_mbps_from_join']);
        }
        
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        
        // Tri
        usort($results, function($a, $b) use ($sort) {
            if ($sort === 'occ_asc') {
                return ($a['nombre_occurrences'] ?? 0) <=> ($b['nombre_occurrences'] ?? 0);
            } elseif ($sort === 'trafic_desc') {
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            } else {
                if (($b['nombre_occurrences'] ?? 0) != ($a['nombre_occurrences'] ?? 0)) {
                    return ($b['nombre_occurrences'] ?? 0) <=> ($a['nombre_occurrences'] ?? 0);
                }
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            }
        });
        
        return $results;
    }

    private function getSitesByBackboneWithDateRange(EntityManagerInterface $em, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc'): array
    {
        $conn = $em->getConnection();
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) return [];

        $baseQuery = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                a.capacite_mbps,
                COALESCE(c.capacite_mbps, c2.capacite_mbps) as capacite_mbps_from_join,
                a.stats_json
            FROM analyse_resultat a
            LEFT JOIN capacite_site c ON (a.site LIKE c.site || \'%\') AND c.type_trans = \'BACKBONE\'
            LEFT JOIN capacite_site c2 ON (a.site_fdd LIKE c2.site || \'%\') AND c2.type_trans = \'BACKBONE\'
            WHERE (UPPER(a.type_trans) LIKE \'BH%\') AND a.date_analyse = :lastDate
        ';
        
        $params = ['lastDate' => $lastDate];
        
        $results = $this->buildSelectQuery($em, $baseQuery, $params, $search, $classFilter);
        
        // Priorité à la capacité stockée dans analyse_resultat
        foreach ($results as &$row) {
            if (($row['capacite_mbps'] ?? 0) <= 0 && ($row['capacite_mbps_from_join'] ?? 0) > 0) {
                $row['capacite_mbps'] = $row['capacite_mbps_from_join'];
            }
            unset($row['capacite_mbps_from_join']);
        }
        
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        
        usort($results, function($a, $b) use ($sort) {
            if ($sort === 'occ_asc') {
                return ($a['nombre_occurrences'] ?? 0) <=> ($b['nombre_occurrences'] ?? 0);
            } elseif ($sort === 'trafic_desc') {
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            } else {
                if (($b['nombre_occurrences'] ?? 0) != ($a['nombre_occurrences'] ?? 0)) {
                    return ($b['nombre_occurrences'] ?? 0) <=> ($a['nombre_occurrences'] ?? 0);
                }
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            }
        });
        
        return $results;
    }

    private function getSitesAllWithDateRange(EntityManagerInterface $em, string $search = '', string $classFilter = '', string $dateDebut = '', string $dateFin = '', string $sort = 'occ_desc'): array
    {
        $conn = $em->getConnection();
        $lastDate = $this->statsService->getLastAnalyseDate();
        if (!$lastDate) return [];

        $baseQuery = '
            SELECT 
                a.id, a.site, a.classification, a.type_trans, a.date_analyse, 
                a.latitude, a.longitude, a.site_fdd, a.site_tdd,
                a.max_trafic, a.nombre_occurrences, a.seuil_critique, a.date_max,
                a.max_trafic_fdd, a.max_trafic_tdd,
                a.capacite_mbps,
                COALESCE(c.capacite_mbps, c2.capacite_mbps) as capacite_mbps_from_join,
                a.stats_json
            FROM analyse_resultat a
            LEFT JOIN capacite_site c ON (a.site LIKE c.site || \'%\') AND c.type_trans = UPPER(a.type_trans)
            LEFT JOIN capacite_site c2 ON (a.site_fdd LIKE c2.site || \'%\') AND c2.type_trans = UPPER(a.type_trans)
            WHERE a.date_analyse = :lastDate
        ';
        
        $params = ['lastDate' => $lastDate];
        
        $results = $this->buildSelectQuery($em, $baseQuery, $params, $search, $classFilter);
        
        // Priorité à la capacité stockée dans analyse_resultat
        foreach ($results as &$row) {
            if (($row['capacite_mbps'] ?? 0) <= 0 && ($row['capacite_mbps_from_join'] ?? 0) > 0) {
                $row['capacite_mbps'] = $row['capacite_mbps_from_join'];
            }
            unset($row['capacite_mbps_from_join']);
        }
        
        $results = $this->postProcessResults($results, $dateDebut, $dateFin);
        
        usort($results, function($a, $b) use ($sort) {
            if ($sort === 'occ_asc') {
                return ($a['nombre_occurrences'] ?? 0) <=> ($b['nombre_occurrences'] ?? 0);
            } elseif ($sort === 'trafic_desc') {
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            } else {
                if (($b['nombre_occurrences'] ?? 0) != ($a['nombre_occurrences'] ?? 0)) {
                    return ($b['nombre_occurrences'] ?? 0) <=> ($a['nombre_occurrences'] ?? 0);
                }
                return ($b['max_trafic'] ?? 0) <=> ($a['max_trafic'] ?? 0);
            }
        });
        
        return $results;
    }

    private function getStatut(int $occurrences): string
    {
        if ($occurrences > 50) {
            return 'CRITIQUE';
        } elseif ($occurrences > 20) {
            return 'SURVEILLANCE';
        } elseif ($occurrences > 0) {
            return 'OK';
        }
        return 'AUCUNE OCCURRENCE';
    }

    // ==================== ROUTES ====================

    #[Route('/superuser/fo', name: 'superuser_fo')]
    public function fo(EntityManagerInterface $em, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesByTypeWithDateRange($em, 'FO', $search, $classFilter, $dateDebut, $dateFin, $sort);
        
        foreach ($sites as &$site) {
            $site['statut'] = $this->getStatut($site['nombre_occurrences'] ?? 0);
            $site['etat'] = $this->getEtatSite(
                $site['max_trafic'] ?? 0, 
                $site['capacite_mbps'] ?? 0, 
                $site['nombre_occurrences'] ?? 0
            );
        }
        
        $stats = $this->statsService->getStatsByType('FO');
        
        return $this->render('dashboard/_transmission_table.html.twig', [
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
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ]);
    }

    #[Route('/superuser/fh', name: 'superuser_fh')]
    public function fh(EntityManagerInterface $em, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesByTypeWithDateRange($em, 'FH', $search, $classFilter, $dateDebut, $dateFin, $sort);
        
        foreach ($sites as &$site) {
            $site['statut'] = $this->getStatut($site['nombre_occurrences'] ?? 0);
            $site['etat'] = $this->getEtatSite(
                $site['max_trafic'] ?? 0, 
                $site['capacite_mbps'] ?? 0, 
                $site['nombre_occurrences'] ?? 0
            );
        }
        
        $stats = $this->statsService->getStatsByType('FH');
        
        return $this->render('dashboard/_transmission_table.html.twig', [
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
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ]);
    }

    #[Route('/superuser/backbone', name: 'superuser_backbone')]
    public function backbone(EntityManagerInterface $em, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesByBackboneWithDateRange($em, $search, $classFilter, $dateDebut, $dateFin, $sort);
        
        foreach ($sites as &$site) {
            $site['statut'] = $this->getStatut($site['nombre_occurrences'] ?? 0);
            $site['etat'] = $this->getEtatSite(
                $site['max_trafic'] ?? 0, 
                $site['capacite_mbps'] ?? 0, 
                $site['nombre_occurrences'] ?? 0
            );
        }
        
        $stats = $this->statsService->getStatsByType('BACKBONE');
        
        return $this->render('dashboard/_transmission_table.html.twig', [
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'BACKBONE',
            'titre'       => 'Backbone Partagé',
            'search'      => $search,
            'classFilter' => $classFilter,
            'date_debut'  => $dateDebut,
            'date_fin'    => $dateFin,
            'sort'        => $sort,
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ]);
    }

    #[Route('/superuser/tous', name: 'superuser_tous')]
    public function tousSites(EntityManagerInterface $em, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');
        
        $sites = $this->getSitesAllWithDateRange($em, $search, $classFilter, $dateDebut, $dateFin, $sort);
        
        foreach ($sites as &$site) {
            $site['statut'] = $this->getStatut($site['nombre_occurrences'] ?? 0);
            $site['etat'] = $this->getEtatSite(
                $site['max_trafic'] ?? 0, 
                $site['capacite_mbps'] ?? 0, 
                $site['nombre_occurrences'] ?? 0
            );
        }
        
        $stats = [
            'total'           => count($sites),
            'total_occ'       => array_sum(array_column($sites, 'nombre_occurrences')),
            'max_trafic'      => !empty($sites) ? max(array_column($sites, 'max_trafic')) : 0,
            'sites_critiques' => count(array_filter($sites, fn($s) => ($s['nombre_occurrences'] ?? 0) > 50)),
            'total_tf'        => count(array_filter($sites, fn($s) => $s['classification'] === 'TF')),
            'total_cotrans'   => count(array_filter($sites, fn($s) => $s['classification'] === 'COTRANS')),
            'total_nocotrans' => count(array_filter($sites, fn($s) => $s['classification'] === 'NO_COTRANS')),
            'total_nontdd'    => count(array_filter($sites, fn($s) => $s['classification'] === 'NON_TDD')),
        ];
        
        return $this->render('dashboard/_transmission_table.html.twig', [
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
            'derniere_date_import' => $this->statsService->getLastAnalyseDate() ? new \DateTime($this->statsService->getLastAnalyseDate()) : null,
        ]);
    }

    // ==================== EXPORT EXCEL ====================

    #[Route('/superuser/export-excel/{type}', name: 'export_transmission_excel')]
    public function exportExcel(EntityManagerInterface $em, Request $request, string $type): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        $dateDebut = $request->query->get('date_debut', '');
        $dateFin = $request->query->get('date_fin', '');
        $sort = $request->query->get('sort', 'occ_desc');

        if ($type === 'TOUS') {
            $sites = $this->getSitesAllWithDateRange($em, $search, $classFilter, $dateDebut, $dateFin, $sort);
        } elseif ($type === 'BACKBONE') {
            $sites = $this->getSitesByBackboneWithDateRange($em, $search, $classFilter, $dateDebut, $dateFin, $sort);
        } else {
            $sites = $this->getSitesByTypeWithDateRange($em, $type, $search, $classFilter, $dateDebut, $dateFin, $sort);
        }

        foreach ($sites as &$site) {
            $site['statut'] = $this->getStatut($site['nombre_occurrences'] ?? 0);
            $site['etat'] = $this->getEtatSite(
                $site['max_trafic'] ?? 0, 
                $site['capacite_mbps'] ?? 0, 
                $site['nombre_occurrences'] ?? 0
            );
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $titrePeriode = ($dateDebut && $dateFin) ? "Période: " . (new \DateTime($dateDebut))->format('d/m/Y') . " → " . (new \DateTime($dateFin))->format('d/m/Y') : "Toute période";
        $sheet->setCellValue('A1', $titrePeriode);
        $sheet->mergeCells('A1:K1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        
        $headers = ['Site', 'Classification', 'Type', 'Max TDD', 'Max FDD', 'Trafic Max', 'Capacité', 'Taux Utilisation', 'Occurrences', 'Statut', 'État'];
        $col = 'A';
        $rowHeader = 2;
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $rowHeader, $header);
            $sheet->getStyle($col . $rowHeader)->getFont()->setBold(true);
            $col++;
        }
        
        $row = 3;
        foreach ($sites as $index => $s) {
            $siteName = $s['site_clean'] ?? $s['site'] ?? '—';
            $maxTdd = $s['max_tdd'] ?? 0;
            $maxFdd = $s['max_fdd'] ?? 0;
            $maxTrafic = $s['max_trafic'] ?? 0;
            $occurrences = $s['nombre_occurrences'] ?? 0;
            $statut = $s['statut'] ?? $this->getStatut($occurrences);
            $etat = $s['etat'] ?? 'OK';
            $capacite = (float)($s['capacite_mbps'] ?? 0);
            
            $utilisation = '';
            if ($capacite > 0 && $maxTrafic > 0) {
                $utilisation = round(($maxTrafic / $capacite) * 100, 2) . '%';
            }
            
            $sheet->setCellValue('A' . $row, $siteName);
            $sheet->setCellValue('B' . $row, $s['classification'] ?? '—');
            $sheet->setCellValue('C' . $row, $s['type_trans'] ?? '—');
            $sheet->setCellValue('D' . $row, number_format($maxTdd, 2, ',', ' '));
            $sheet->setCellValue('E' . $row, number_format($maxFdd, 2, ',', ' '));
            $sheet->setCellValue('F' . $row, number_format($maxTrafic, 2, ',', ' '));
            $sheet->setCellValue('G' . $row, $capacite > 0 ? number_format($capacite, 0, ',', ' ') . ' Mbps' : 'Non définie');
            $sheet->setCellValue('H' . $row, $utilisation);
            $sheet->setCellValue('I' . $row, $occurrences);
            $sheet->setCellValue('J' . $row, $statut);
            $sheet->setCellValue('K' . $row, $etat);
            $row++;
        }
        
        foreach (range('A', 'K') as $col) {
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
}