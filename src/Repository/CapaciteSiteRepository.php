<?php
// src/Repository/CapaciteSiteRepository.php

namespace App\Repository;

use App\Entity\CapaciteSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CapaciteSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CapaciteSite::class);
    }

    public function findCapaciteBySite(string $site, ?string $typeTrans = null): ?CapaciteSite
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.site = :site')
            ->setParameter('site', $site);

        if ($typeTrans) {
            $qb->andWhere('c.typeTrans = :type')
               ->setParameter('type', $typeTrans);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findCapacitesBySites(array $sites): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.site IN (:sites)')
            ->setParameter('sites', $sites)
            ->getQuery()
            ->getResult();
    }

    public function getCapacitesGroupedBySite(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.site', 'c.typeTrans', 'c.capaciteMbps')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row['site']][$row['typeTrans']] = $row['capaciteMbps'];
        }
        return $grouped;
    }
}