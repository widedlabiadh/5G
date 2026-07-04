<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_site')]
#[ORM\Index(name: 'idx_ticket_site_ticket', columns: ['ticket_id'])]
class TicketSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ticketSites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 120)]
    private string $siteName = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $serviceName = null;

    #[ORM\Column(nullable: true)]
    private ?float $maxTrafic = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceSiteId = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $umptType = null;

    #[ORM\Column(nullable: true)]
    private ?bool $swapRequired = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $swapComment = null;

    public function getId(): ?int { return $this->id; }
    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }
    public function getSiteName(): string { return $this->siteName; }
    public function setSiteName(string $siteName): static { $this->siteName = $siteName; return $this; }
    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(?string $typeTrans): static { $this->typeTrans = $typeTrans; return $this; }
    public function getServiceName(): ?string { return $this->serviceName; }
    public function setServiceName(?string $serviceName): static { $this->serviceName = $serviceName; return $this; }
    public function getMaxTrafic(): ?float { return $this->maxTrafic; }
    public function setMaxTrafic(?float $maxTrafic): static { $this->maxTrafic = $maxTrafic; return $this; }
    public function getSourceSiteId(): ?int { return $this->sourceSiteId; }
    public function setSourceSiteId(?int $sourceSiteId): static { $this->sourceSiteId = $sourceSiteId; return $this; }
    public function getUmptType(): ?string { return $this->umptType; }
    public function setUmptType(?string $umptType): static { $this->umptType = $umptType; return $this; }
    public function isSwapRequired(): ?bool { return $this->swapRequired; }
    public function setSwapRequired(?bool $swapRequired): static { $this->swapRequired = $swapRequired; return $this; }
    public function getSwapComment(): ?string { return $this->swapComment; }
    public function setSwapComment(?string $swapComment): static { $this->swapComment = $swapComment; return $this; }
}
