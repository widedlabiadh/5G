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

    // Méthodes personnalisées
    public function findSitesCritiques(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.nombreOccurrences > 50')
            ->orderBy('a.nombreOccurrences', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getStatsByType(): array
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
}