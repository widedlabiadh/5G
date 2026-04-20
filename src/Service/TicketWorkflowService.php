<?php
// src/Service/TicketWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class TicketWorkflowService
{
    private EntityManagerInterface $em;
    private TicketHistoryService $historyService;
    private NotificationService $notificationService;

    public function __construct(
        EntityManagerInterface $em,
        TicketHistoryService $historyService,
        NotificationService $notificationService
    ) {
        $this->em = $em;
        $this->historyService = $historyService;
        $this->notificationService = $notificationService;
    }

    public function refreshProgress(Ticket $ticket): void
    {
        $tasks = $ticket->getTasks();
        $total = count($tasks);
        
        if ($total === 0) {
            $ticket->setProgress(0);
            return;
        }

        $done = 0;
        foreach ($tasks as $task) {
            if ($task->getStatus() === 'done') {
                $done++;
            }
        }

        $progress = (int) round(($done / $total) * 100);
        $ticket->setProgress($progress);

        // Auto-update status
        if ($progress >= 100 && $ticket->getStatus() !== 'closed') {
            $this->transition($ticket, 'validate');
        } elseif ($progress > 0 && $ticket->getStatus() === 'open') {
            $this->transition($ticket, 'start');
        }

        $this->em->flush();
    }

    public function transition(Ticket $ticket, string $transition, ?User $user = null, ?string $comment = null): bool
    {
        $user = $user ?? $ticket->getCreatedBy();
        $oldStatus = $ticket->getStatus();
        
        $newStatus = match($transition) {
            'start' => 'in_progress',
            'validate' => 'pending_validation',
            'approve' => 'closed',
            'reject' => 'open',
            'cancel' => 'cancelled',
            default => null,
        };

        if (!$newStatus || $newStatus === $oldStatus) {
            return false;
        }

        // Validation rules
        if ($transition === 'approve' && $ticket->getProgress() < 100) {
            return false;
        }

        $ticket->setStatus($newStatus);
        
        if ($newStatus === 'closed') {
            $ticket->setClosedAt(new \DateTime());
        }

        $this->em->flush();

        // Historique
        $this->historyService->add($ticket, $user, 'status_changed', [
            'from' => $oldStatus,
            'to' => $newStatus,
            'comment' => $comment,
            'transition' => $transition,
        ]);

        // Notifications
        $this->notificationService->notifyStatusChange($ticket, $oldStatus, $newStatus, $user);

        return true;
    }

    public function assignTask(TicketTask $task, User $assigner, ?string $comment = null): void
    {
        $this->historyService->add($task->getTicket(), $assigner, 'task_assigned', [
            'task_id' => $task->getId(),
            'task_title' => $task->getTitle(),
            'assigned_to' => $task->getAssignedTo()->getFullName(),
            'comment' => $comment,
        ]);

        $this->notificationService->notifyTaskAssigned($task);
    }

    public function completeTask(TicketTask $task, User $user, ?string $comment = null): void
    {
        $task->setStatus('done');
        $task->setCompletedAt(new \DateTime());
        $task->setComment($comment);
        
        $this->em->flush();

        $this->historyService->add($task->getTicket(), $user, 'task_completed', [
            'task_id' => $task->getId(),
            'task_title' => $task->getTitle(),
            'comment' => $comment,
        ]);

        $this->refreshProgress($task->getTicket());
        $this->notificationService->notifyTaskCompleted($task);
    }
}