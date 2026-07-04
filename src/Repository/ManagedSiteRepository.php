<?php

namespace App\Repository;

use App\Entity\ManagedSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManagedSite>
 */
class ManagedSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagedSite::class);
    }

    /**
     * @return ManagedSite[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ManagedSite[]
     */
    public function search(?string $term): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        $term = trim((string) $term);
        if ($term !== '') {
            $qb->andWhere('LOWER(s.name) LIKE :term OR LOWER(COALESCE(s.description, \'\')) LIKE :term OR LOWER(COALESCE(s.typeTrans, \'\')) LIKE :term OR LOWER(COALESCE(s.classification, \'\')) LIKE :term')
                ->setParameter('term', '%' . mb_strtolower($term) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByName(string $name): ?ManagedSite
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findOneByNameInsensitive(string $name): ?ManagedSite
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return $this->createQueryBuilder('s')
            ->andWhere('LOWER(s.name) = :name')
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param string[] $names
     * @return array<string, ManagedSite>
     */
    public function findByNamesInsensitiveIndexed(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $lower = array_values(array_unique(array_map('mb_strtolower', $names)));

        $sites = $this->createQueryBuilder('s')
            ->andWhere('LOWER(s.name) IN (:names)')
            ->setParameter('names', $lower)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($sites as $site) {
            if ($site instanceof ManagedSite) {
                $indexed[mb_strtolower(trim((string) $site->getName()))] = $site;
            }
        }

        return $indexed;
    }

    /**
     * @param string[] $names
     * @return array<string, ManagedSite>
     */
    public function findByNamesIndexed(array $names): array
    {
        $names = array_values(array_filter(array_map('trim', $names), static fn (string $value): bool => $value !== ''));
        if ($names === []) {
            return [];
        }

        $sites = $this->createQueryBuilder('s')
            ->andWhere('s.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($sites as $site) {
            if ($site instanceof ManagedSite) {
                $indexed[mb_strtolower(trim((string) $site->getName()))] = $site;
            }
        }

        return $indexed;
    }
}
