<?php

namespace App\Service;

use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class WorkflowEngineService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $workflowService,
        private TicketHistoryService $historyService,
        private NotificationService $notificationService
    ) {
    }

    public function startTask(TicketTask $task, User $user): void
    {
        if ($task->getStatus() !== TicketTask::STATUS_PENDING) {
            return;
        }

        $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
        $task->setStartedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $ticket = $task->getTicket();
        if ($ticket && $ticket->getStatus() === 'open') {
            $ticket->setStatus('in_progress');
        }

        if ($ticket) {
            $this->historyService->add($ticket, $user, 'task_started', sprintf('Tâche "%s" démarrée.', $task->getTitle()));
            $this->workflowService->refreshProgress($ticket);
        }
    }

    public function choicesFor(TicketTask $task): array
    {
        $stepCode = $task->getStepCode();

        return match ($stepCode) {
            'ip_analysis' => [
                'OK - Continuer' => 'ok',
                'NOK - Blocage FO' => 'nok_fo',
                'NOK - Swap requis' => 'nok_swap',
            ],
            default => [
                'OK - Terminé' => 'ok',
                'NOK - À revoir' => 'nok',
                'Bloqué' => 'blocked',
            ],
        };
    }

    public function completeTaskWithSiteValidation(
        TicketTask $task,
        User $user,
        string $decision,
        ?string $siteValidated = null,
        ?string $comment = null,
        ?string $proofFile = null
    ): void {
        $task->setDecision($decision);
        $task->setComment($comment);
        $task->setProofFile($proofFile);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());
        $task->setStatus($decision === 'blocked' ? TicketTask::STATUS_BLOCKED : TicketTask::STATUS_DONE);

        $ticket = $task->getTicket();
        if ($ticket) {
            if ($decision === 'blocked') {
                $ticket->setStatus('in_progress');
            }

            $details = sprintf('Tâche "%s" traitée avec décision "%s".', $task->getTitle(), $decision);
            if ($siteValidated) {
                $details .= ' Site: ' . $siteValidated . '.';
            }

            $this->historyService->add($ticket, $user, 'task_completed', $details);
            $this->workflowService->refreshProgress($ticket);
            $this->notificationService->notifyTaskCompleted($task);
        }

        $this->em->flush();
    }
}
