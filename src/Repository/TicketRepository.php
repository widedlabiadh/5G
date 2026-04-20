<?php
// src/Repository/TicketRepository.php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findAllOptimized(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'u')->addSelect('u')
            ->leftJoin('t.tasks', 'tsk')->addSelect('tsk')
            ->leftJoin('t.site', 's')->addSelect('s')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserOptimized(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'u')->addSelect('u')
            ->leftJoin('t.tasks', 'tsk')->addSelect('tsk')
            ->leftJoin('tsk.assignedTo', 'a')->addSelect('a')
            ->leftJoin('t.site', 's')->addSelect('s')
            ->andWhere('t.createdBy = :user OR a.id = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getKanbanCards(): array
    {
        $statuses = ['open', 'in_progress', 'pending_validation', 'closed'];
        $result = [];
        
        foreach ($statuses as $status) {
            $result[$status] = $this->createQueryBuilder('t')
                ->leftJoin('t.createdBy', 'u')->addSelect('u')
                ->leftJoin('t.tasks', 'tsk')->addSelect('tsk')
                ->leftJoin('t.site', 's')->addSelect('s')
                ->andWhere('t.status = :status')
                ->setParameter('status', $status)
                ->orderBy('t.deadlineAt', 'ASC')
                ->getQuery()
                ->getResult();
        }
        
        return $result;
    }

    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('t');
        
        return [
            'total' => (int) $qb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult(),
            'open' => $this->countByStatus('open'),
            'in_progress' => $this->countByStatus('in_progress'),
            'pending' => $this->countByStatus('pending_validation'),
            'closed' => $this->countByStatus('closed'),
            'overdue' => $this->countOverdue(),
        ];
    }

    private function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countOverdue(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.deadlineAt IS NOT NULL')
            ->andWhere('t.deadlineAt < :now')
            ->andWhere('t.status NOT IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['closed', 'cancelled'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}