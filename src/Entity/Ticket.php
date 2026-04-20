<?php
// src/Entity/Ticket.php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\Index(name: 'idx_ticket_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ticket_deadline', columns: ['deadline_at'])]
#[ORM\Index(name: 'idx_ticket_created', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private string $actionType = 'swap_10g';

    #[ORM\Column(length: 50)]
    private string $status = 'open';

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deadlineAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: AnalyseResultat::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AnalyseResultat $site = null;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketTask::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tasks;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketComment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $comments;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketHistory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $history;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketNotification::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $notifications;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getTitle(): ?string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getActionType(): ?string { return $this->actionType; }
    public function getStatus(): string { return $this->status; }
    public function getProgress(): int { return $this->progress; }
    public function getDeadlineAt(): ?\DateTimeInterface { return $this->deadlineAt; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function getClosedAt(): ?\DateTimeInterface { return $this->closedAt; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getSite(): ?AnalyseResultat { return $this->site; }
    public function getTasks(): Collection { return $this->tasks; }
    public function getComments(): Collection { return $this->comments; }
    public function getHistory(): Collection { return $this->history; }
    public function getNotifications(): Collection { return $this->notifications; }

    // Setters
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function setActionType(string $actionType): static { $this->actionType = $actionType; return $this; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function setProgress(int $progress): static { $this->progress = $progress; return $this; }
    public function setDeadlineAt(?\DateTimeInterface $deadlineAt): static { $this->deadlineAt = $deadlineAt; return $this; }
    public function setClosedAt(?\DateTimeInterface $closedAt): static { $this->closedAt = $closedAt; return $this; }
    public function setCreatedBy(?User $createdBy): static { $this->createdBy = $createdBy; return $this; }
    public function setSite(?AnalyseResultat $site): static { $this->site = $site; return $this; }

    // Méthodes utilitaires
    public function isOverdue(): bool
    {
        return $this->deadlineAt && $this->deadlineAt < new \DateTime() && !in_array($this->status, ['closed', 'cancelled']);
    }

    public function getActionTypeLabel(): string
    {
        return match($this->actionType) {
            'swap_10g' => '🔄 Swap 10G',
            'tf_balancing' => '⚖️ T+F Balancing',
            'upgrade_capacite' => '📈 Upgrade capacité',
            'intervention_urgence' => '🚨 Intervention urgence',
            default => $this->actionType,
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'open' => '📋 Ouvert',
            'in_progress' => '⚙️ En cours',
            'pending_validation' => '✅ En validation',
            'closed' => '✔️ Clôturé',
            'cancelled' => '❌ Annulé',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'open' => '#f97316',
            'in_progress' => '#3b82f6',
            'pending_validation' => '#8b5cf6',
            'closed' => '#10b981',
            'cancelled' => '#ef4444',
            default => '#64748b',
        };
    }
}