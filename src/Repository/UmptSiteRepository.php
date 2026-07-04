<?php

namespace App\Repository;

use App\Entity\UmptSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UmptSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UmptSite::class);
    }

    public function findOneByNormalizedSite(string $site): ?UmptSite
    {
        return $this->findOneBy(['site' => self::normalizeSite($site)]);
    }

    /**
     * @return array<string, UmptSite>
     */
    public function findMapBySites(array $sites): array
    {
        $normalizedSites = array_values(array_unique(array_filter(
            array_map([self::class, 'normalizeSite'], $sites),
            static fn (string $site): bool => $site !== ''
        )));

        if ($normalizedSites === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.site IN (:sites)')
            ->setParameter('sites', $normalizedSites)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            if ($row instanceof UmptSite) {
                $map[$row->getSite()] = $row;
            }
        }

        return $map;
    }

    public static function normalizeSite(mixed $site): string
    {
        return strtoupper(trim((string) $site));
    }
}
