<?php
// src/Repository/TicketTaskRepository.php

namespace App\Repository;

use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTask::class);
    }

    public function findByAssignedUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->andWhere('tt.assignedTo = :user')
            ->setParameter('user', $user)
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPendingByUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->andWhere('tt.assignedTo = :user')
            ->andWhere('tt.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->orderBy('t.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}