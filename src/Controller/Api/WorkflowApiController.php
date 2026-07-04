<?php
// src/Controller/Api/WorkflowApiController.php

namespace App\Controller\Api;

use App\DTO\TicketSummaryDTO;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Repository\TicketNotificationRepository;
use App\Repository\TicketRepository;
use App\Service\TicketWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/workflow')]
class WorkflowApiController extends AbstractController
{
    #[Route('/ticket/{id}/status', name: 'api_workflow_change_status', methods: ['POST'])]
    public function changeStatus(
        Ticket $ticket,
        Request $request,
        TicketWorkflowService $workflowService
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        $data = json_decode($request->getContent(), true);
        $transition = $data['transition'] ?? null;
        $comment = $data['comment'] ?? null;
        
        if (!$transition) {
            return $this->json(['success' => false, 'error' => 'Transition requise'], 400);
        }
        
        $success = $workflowService->transition($ticket, $transition, $this->getUser(), $comment);
        
        if (!$success) {
            return $this->json(['success' => false, 'error' => 'Transition invalide'], 400);
        }
        
        return $this->json([
            'success' => true,
            'ticket' => TicketSummaryDTO::fromEntity($ticket, 'superuser'),
        ]);
    }

    #[Route('/task/{id}/complete', name: 'api_workflow_complete_task', methods: ['POST'])]
    public function completeTask(
        TicketTask $task,
        Request $request,
        TicketWorkflowService $workflowService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $comment = $data['comment'] ?? null;
        
        $workflowService->completeTask($task, $this->getUser(), $comment);
        
        return $this->json([
            'success' => true,
            'ticket' => TicketSummaryDTO::fromEntity($task->getTicket(), $this->getUser()->getRole()),
        ]);
    }

    #[Route('/notifications/unread', name: 'api_workflow_unread_notifications')]
    public function unreadNotifications(TicketNotificationRepository $notificationRepository): JsonResponse
    {
        $notifications = $notificationRepository->findUnreadByUser($this->getUser(), 8);
        $count = $notificationRepository->countUnreadByUser($this->getUser());
        
        return $this->json([
            'count' => $count,
            'notifications' => array_map(fn($n) => [
                'id' => $n->getId(),
                'title' => $n->getTitle(),
                'message' => $n->getMessage(),
                'type' => $n->getType(),
                'ticketId' => $n->getTicket()?->getId(),
                'ticketUrl' => $n->getTicket() ? $this->generateUrl('workflow_show', ['id' => $n->getTicket()->getId()]) : null,
                'createdAt' => $n->getCreatedAt()->format('d/m/Y H:i'),
            ], $notifications),
        ]);
    }

    #[Route('/notifications/mark-read', name: 'api_workflow_mark_read', methods: ['POST'])]
    public function markNotificationsRead(TicketNotificationRepository $notificationRepository): JsonResponse
    {
        $notificationRepository->markAllAsRead($this->getUser());
        
        return $this->json(['success' => true]);
    }
}
