<?php
// src/Entity/TicketTask.php

namespace App\Entity;

use App\Repository\TicketTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketTaskRepository::class)]
#[ORM\Table(name: 'ticket_task')]
#[ORM\Index(name: 'idx_task_status', columns: ['status'])]
#[ORM\Index(name: 'idx_task_assigned', columns: ['assigned_to_id'])]
class TicketTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofFile = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getTicket(): ?Ticket { return $this->ticket; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function getTitle(): ?string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getStatus(): string { return $this->status; }
    public function getComment(): ?string { return $this->comment; }
    public function getProofFile(): ?string { return $this->proofFile; }
    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeInterface { return $this->completedAt; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }

    // Setters
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }
    public function setAssignedTo(?User $assignedTo): static { $this->assignedTo = $assignedTo; return $this; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }
    public function setProofFile(?string $proofFile): static { $this->proofFile = $proofFile; return $this; }
    public function setStartedAt(?\DateTimeInterface $startedAt): static { $this->startedAt = $startedAt; return $this; }
    public function setCompletedAt(?\DateTimeInterface $completedAt): static { $this->completedAt = $completedAt; return $this; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => '⏳ En attente',
            'in_progress' => '⚙️ En cours',
            'done' => '✅ Terminé',
            'rejected' => '❌ Rejeté',
            'blocked' => '🔒 Bloqué',
            default => $this->status,
        };
    }
}