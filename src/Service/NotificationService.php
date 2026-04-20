<?php
// src/Service/NotificationService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketNotification;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function create(Ticket $ticket, User $user, string $title, string $message, string $type = 'info'): void
    {
        $notification = new TicketNotification();
        $notification->setTicket($ticket);
        $notification->setUser($user);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($type);

        $this->em->persist($notification);
        $this->em->flush();
    }

    public function notifyStatusChange(Ticket $ticket, string $oldStatus, string $newStatus, User $changedBy): void
    {
        $users = $this->getConcernedUsers($ticket);
        
        $title = "Statut du ticket #{$ticket->getId()} modifié";
        $message = "Le ticket '{$ticket->getTitle()}' est passé de {$oldStatus} à {$newStatus} par {$changedBy->getFullName()}";

        foreach ($users as $user) {
            if ($user->getId() !== $changedBy->getId()) {
                $this->create($ticket, $user, $title, $message, 'status_change');
            }
        }
    }

    public function notifyTaskAssigned(TicketTask $task): void
    {
        $user = $task->getAssignedTo();
        $ticket = $task->getTicket();
        
        $this->create(
            $ticket,
            $user,
            "Nouvelle tâche assignée",
            "Vous avez été assigné à la tâche '{$task->getTitle()}' du ticket #{$ticket->getId()}",
            'assignment'
        );
    }

    public function notifyTaskCompleted(TicketTask $task): void
    {
        $ticket = $task->getTicket();
        $creator = $ticket->getCreatedBy();
        
        $this->create(
            $ticket,
            $creator,
            "Tâche terminée",
            "La tâche '{$task->getTitle()}' a été complétée par {$task->getAssignedTo()->getFullName()}",
            'completion'
        );
    }

    private function getConcernedUsers(Ticket $ticket): array
    {
        $users = [$ticket->getCreatedBy()];
        
        foreach ($ticket->getTasks() as $task) {
            $assignedTo = $task->getAssignedTo();
            if (!in_array($assignedTo, $users)) {
                $users[] = $assignedTo;
            }
        }
        
        return $users;
    }
}