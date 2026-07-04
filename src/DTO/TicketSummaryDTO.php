<?php
// src/DTO/TicketSummaryDTO.php

namespace App\DTO;

class TicketSummaryDTO
{
    public int $id;
    public string $title;
    public string $status;
    public string $statusColor;
    public string $statusLabel;
    public string $actionTypeLabel;
    public int $progress;
    public ?string $deadline;
    public string $createdBy;
    public string $createdAt;
    public ?string $siteName;
    public int $tasksCount;
    public int $tasksDoneCount;
    public bool $isOverdue;
    public bool $canTransition;

    public static function fromEntity($ticket, ?string $role = null): self
    {
        $dto = new self();
        $dto->id = $ticket->getId();
        $dto->title = $ticket->getTitle();
        $dto->status = $ticket->getStatus();
        $dto->statusColor = $ticket->getStatusColor();
        $dto->statusLabel = $ticket->getStatusLabel();
        $dto->actionTypeLabel = $ticket->getActionTypeLabel();
        $dto->progress = $ticket->getProgress();
        
        // Gestion de la deadline
        $deadline = $ticket->getDeadlineAt();
        if ($deadline instanceof \DateTimeInterface) {
            $dto->deadline = $deadline->format('d/m/Y');
        } else {
            $dto->deadline = null;
        }
        
        $dto->createdBy = $ticket->getCreatedBy()?->getFullName() ?? $ticket->getCreatedBy()?->getEmail() ?? 'N/A';
        
        // Gestion de la date de création (supporte DateTimeInterface et string)
        $createdAt = $ticket->getCreatedAt();
        if ($createdAt instanceof \DateTimeInterface) {
            $dto->createdAt = $createdAt->format('d/m/Y H:i');
        } elseif (is_string($createdAt)) {
            // Si c'est une chaîne, essayer de la convertir
            try {
                $date = new \DateTime($createdAt);
                $dto->createdAt = $date->format('d/m/Y H:i');
            } catch (\Exception $e) {
                $dto->createdAt = $createdAt;
            }
        } else {
            $dto->createdAt = 'N/A';
        }
        
        if (method_exists($ticket, 'getTicketSites') && !$ticket->getTicketSites()->isEmpty()) {
            $names = [];
            foreach ($ticket->getTicketSites() as $ticketSite) {
                $names[] = $ticketSite->getSiteName();
            }
            $dto->siteName = implode(', ', array_slice($names, 0, 3));
            if (count($names) > 3) {
                $dto->siteName .= sprintf(' +%d', count($names) - 3);
            }
        } else {
            $dto->siteName = $ticket->getSite()?->getSite() ?? 'Non spécifié';
        }
        $dto->tasksCount = $ticket->getTasks()->count();
        $dto->tasksDoneCount = 0;
        $dto->isOverdue = $ticket->isOverdue();
        $dto->canTransition = $role === 'superuser' || $role === 'admin';
        
        foreach ($ticket->getTasks() as $task) {
            if ($task->getStatus() === 'done') {
                $dto->tasksDoneCount++;
            }
        }
        
        return $dto;
    }
}
