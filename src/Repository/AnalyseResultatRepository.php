<?php
// src/Repository/AnalyseResultatRepository.php

namespace App\Repository;

use App\Entity\AnalyseResultat;
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
     * Sites avec plus de 50 occurrences (critiques).
     */
    public function findSitesCritiques(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.nombreOccurrences > 50')
            ->orderBy('a.nombreOccurrences', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
                 WHERE (type_trans = :exact OR type_trans LIKE :pattern) AND nombre_occurrences > 50 AND date_analyse = :date',
                ['exact' => 'BACKBONE', 'pattern' => 'BH%', 'date' => $lastDate]
            );
        } else {
            $t = strtoupper($type);
            $total = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date',
                ['type' => $t, 'date' => $lastDate]
            );
            $occ = $conn->fetchOne(
                'SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date',
                ['type' => $t, 'date' => $lastDate]
            );
            $max = $conn->fetchOne(
                'SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :date',
                ['type' => $t, 'date' => $lastDate]
            );
            $critiques = $conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = :type AND nombre_occurrences > 50 AND date_analyse = :date',
                ['type' => $t, 'date' => $lastDate]
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

        return $conn->fetchAllAssociative(
            'SELECT id, site, classification, type_trans, max_trafic, seuil_critique, 
                    nombre_occurrences, latitude, longitude, date_analyse 
             FROM analyse_resultat 
             WHERE date_analyse = :date 
             ORDER BY site',
            ['date' => $lastDate]
        );
    }
}