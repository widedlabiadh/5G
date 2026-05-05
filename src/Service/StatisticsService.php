<?php

namespace App\Service;

use App\Entity\AnalyseResultat;
use App\Repository\AnalyseResultatRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class StatisticsService
{
    private Connection $conn;
    private AnalyseResultatRepository $analyseRepo;

    public function __construct(EntityManagerInterface $em, AnalyseResultatRepository $analyseRepo)
    {
        $this->conn = $em->getConnection();
        $this->analyseRepo = $analyseRepo;
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
            'sites_tf'             => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'TF', 'date' => $lastDate]),
            'sites_cotrans'        => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'COTRANS', 'date' => $lastDate]),
            'sites_nocotrans'      => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NO_COTRANS', 'date' => $lastDate]),
            'sites_nontdd'         => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NON_TDD', 'date' => $lastDate]),
            'total_occurrences'    => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE date_analyse = :date', ['date' => $lastDate]),
            'fo'                   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FO', 'date' => $lastDate]),
            'fh'                   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FH', 'date' => $lastDate]),
            'backbone'             => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
            'sans_type'            => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'NON_DEFINI', 'date' => $lastDate]),
            'fo_congestions'       => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FO', 'date' => $lastDate]),
            'fh_congestions'       => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FH', 'date' => $lastDate]),
            'backbone_congestions' => (int)$this->conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
            'max_trafic_fo'        => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FO', 'date' => $lastDate]),
            'max_trafic_fh'        => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date', ['type' => 'FH', 'date' => $lastDate]),
            'max_trafic_backbone'  => (float)$this->conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND date_analyse = :date', ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]),
        ];
    }

    private function emptyGlobalStats(): array
    {
        return [
            'total_sites' => 0, 'sites_tf' => 0, 'sites_cotrans' => 0,
            'sites_nocotrans' => 0, 'sites_nontdd' => 0, 'total_occurrences' => 0,
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
            $sqlCrit = 'SELECT COUNT(*) FROM analyse_resultat WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND nombre_occurrences > 50 AND date_analyse = :date';
        } else {
            $t = strtoupper($type);
            $sqlTotal = 'SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date';
            $sqlOcc = 'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date';
            $sqlMax = 'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date';
            $sqlCrit = 'SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND nombre_occurrences > 50 AND date_analyse = :date';
        }

        $params = ['date' => $lastDate];
        if ($type !== 'BACKBONE') {
            $params['type'] = $t;
        } else {
            $params['exact'] = 'BACKBONE';
            $params['pattern'] = 'BH%';
        }

        return [
            'total'             => (int)$this->conn->fetchOne($sqlTotal, $params),
            'total_tf'          => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'TF', 'date' => $lastDate]),
            'total_cotrans'     => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'COTRANS', 'date' => $lastDate]),
            'total_nocotrans'   => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE classification = :class AND date_analyse = :date', ['class' => 'NO_COTRANS', 'date' => $lastDate]),
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
            'total_nocotrans' => 0, 'total_nontdd' => 0,
            'total_occ' => 0, 'max_trafic' => 0, 'sites_critiques' => 0,
        ];
    }

    /**
     * Récupère les sites critiques (occurrences > 50) avec limite.
     * Utilisé pour l'affichage dans la carte (10 ou 20 premiers).
     */
    public function getSitesCritiques(int $limit = 20): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) return [];

        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat WHERE nombre_occurrences > 50 AND date_analyse = :date ORDER BY nombre_occurrences DESC LIMIT :limit',
            ['date' => $lastDate, 'limit' => $limit]
        );

        $sites = [];
        foreach ($rows as $row) {
            $site = new \App\Entity\AnalyseResultat();
            $site->setSite($row['site']);
            $site->setClassification($row['classification']);
            $site->setTypeTrans($row['type_trans']);
            $site->setMaxTrafic((float)$row['max_trafic']);
            $site->setSeuilCritique((float)$row['seuil_critique']);
            $site->setNombreOccurrences((int)$row['nombre_occurrences']);
            $site->setTotalMeasures((int)$row['total_measures']);
            $site->setDateAnalyse(new \DateTime($row['date_analyse']));
            $sites[] = $site;
        }
        return $sites;
    }

    /**
     * Retourne le nombre TOTAL de sites critiques (occurrences > 50) sans limite.
     * Utilisé pour le compteur dans les statistiques.
     */
    public function getTotalSitesCritiquesCount(): int
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) {
            return 0;
        }
        
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM analyse_resultat WHERE nombre_occurrences > 50 AND date_analyse = :date',
            ['date' => $lastDate]
        );
        
        return (int) $count;
    }

    /**
     * Retourne tous les sites avec coordonnées GPS (pour la carte).
     */
    public function getSitesForGps(\App\Service\GeocodingService $geocodingService): array
    {
        $lastDate = $this->getLastAnalyseDate();
        if (!$lastDate) return [];

        $results = $this->conn->fetchAllAssociative(
            'SELECT id, site, classification, type_trans, max_trafic, seuil_critique, nombre_occurrences, latitude, longitude, date_analyse 
             FROM analyse_resultat WHERE date_analyse = :date ORDER BY site',
            ['date' => $lastDate]
        );

        $sites = [];
        foreach ($results as $row) {
            $occurrences = (int)$row['nombre_occurrences'];
            $statut = $occurrences > 50 ? 'critique' : ($occurrences > 20 ? 'sous_observation' : ($occurrences > 0 ? 'securise' : 'non_defini'));
            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;

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
                'estimation2026' => $this->calculateEstimation($occurrences, (float)$row['max_trafic']),
            ];
        }
        return $sites;
    }

    private function calculateEstimation(int $occurrences, float $maxTrafic): int
    {
        if ($occurrences > 50) return 25;
        if ($occurrences > 20) return 15;
        if ($occurrences > 0) return 8;
        if ($maxTrafic > 500) return 10;
        return 3;
    }
}