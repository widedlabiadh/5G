<?php
// src/Entity/CapaciteSite.php

namespace App\Entity;

use App\Repository\CapaciteSiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CapaciteSiteRepository::class)]
#[ORM\Table(name: 'capacite_site')]
#[ORM\UniqueConstraint(name: 'unique_site_type', columns: ['site', 'type_trans'])]
class CapaciteSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $site = null;

    #[ORM\Column(length: 20)]
    private ?string $typeTrans = null;

   #[ORM\Column(type: 'float', nullable: true)]
private ?float $capaciteMbps = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateMiseAJour = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFichier = null;

    public function __construct()
    {
        $this->dateMiseAJour = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): static { $this->site = $site; return $this; }
    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(string $typeTrans): static { $this->typeTrans = $typeTrans; return $this; }
    public function getCapaciteMbps(): ?float { return $this->capaciteMbps; }
    public function setCapaciteMbps(float $capaciteMbps): static { $this->capaciteMbps = $capaciteMbps; return $this; }
    public function getDateMiseAJour(): ?\DateTimeInterface { return $this->dateMiseAJour; }
    public function setDateMiseAJour(\DateTimeInterface $dateMiseAJour): static { $this->dateMiseAJour = $dateMiseAJour; return $this; }
    public function getSourceFichier(): ?string { return $this->sourceFichier; }
    public function setSourceFichier(?string $sourceFichier): static { $this->sourceFichier = $sourceFichier; return $this; }
}