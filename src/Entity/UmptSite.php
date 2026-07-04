<?php

namespace App\Entity;

use App\Repository\UmptSiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UmptSiteRepository::class)]
#[ORM\Table(name: 'umpt_site')]
#[ORM\UniqueConstraint(name: 'uniq_umpt_site', columns: ['site'])]
class UmptSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $site = '';

    #[ORM\Column(length: 30)]
    private string $cardType = '';

    #[ORM\Column]
    private bool $swapRequired = false;

    #[ORM\Column(type: 'text')]
    private string $swapComment = '';

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFile = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): string { return $this->site; }
    public function setSite(string $site): static { $this->site = $site; return $this; }
    public function getCardType(): string { return $this->cardType; }
    public function setCardType(string $cardType): static { $this->cardType = $cardType; return $this; }
    public function isSwapRequired(): bool { return $this->swapRequired; }
    public function setSwapRequired(bool $swapRequired): static { $this->swapRequired = $swapRequired; return $this; }
    public function getSwapComment(): string { return $this->swapComment; }
    public function setSwapComment(string $swapComment): static { $this->swapComment = $swapComment; return $this; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getSourceFile(): ?string { return $this->sourceFile; }
    public function setSourceFile(?string $sourceFile): static { $this->sourceFile = $sourceFile; return $this; }
}
