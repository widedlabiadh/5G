<?php

namespace App\Repository;

use App\Entity\SiteData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SiteDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteData::class);
    }

    public function findAllEnodeNames(): array
    {
        return $this->createQueryBuilder('s')
            ->select('DISTINCT s.enode_name')
            ->orderBy('s.enode_name', 'ASC')
            ->getQuery()
            ->getResult();
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
        
        $sql = 'SELECT * FROM site_data';
        $conditions = [];
        $params = [];
        
        if (!empty($sitesList)) {
            $conditions[] = 'enode_name IN (' . implode(', ', array_fill(0, count($sitesList), '?')) . ')';
            $params = array_merge($params, array_values($sitesList));
        }
        
        if ($dateRange !== 'all' && !empty($dateValue)) {
            $date = new \DateTime($dateValue);
            $year = $date->format('Y');
            $month = $date->format('m');
            $day = $date->format('d');
            
            switch ($dateRange) {
                case 'day':
                    $conditions[] = 'EXTRACT(YEAR FROM updated_at) = ? AND EXTRACT(MONTH FROM updated_at) = ? AND EXTRACT(DAY FROM updated_at) = ?';
                    $params[] = $year;
                    $params[] = $month;
                    $params[] = $day;
                    break;
                case 'week':
                    $conditions[] = 'EXTRACT(YEAR FROM updated_at) = ? AND EXTRACT(WEEK FROM updated_at) = ?';
                    $params[] = $year;
                    $params[] = $date->format('W');
                    break;
                case 'month':
                    $conditions[] = 'EXTRACT(YEAR FROM updated_at) = ? AND EXTRACT(MONTH FROM updated_at) = ?';
                    $params[] = $year;
                    $params[] = $month;
                    break;
            }
        }
        
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        $stmt = $conn->executeQuery($sql, $params);
        
        return $stmt->fetchAllAssociative();
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