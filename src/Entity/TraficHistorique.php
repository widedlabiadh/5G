<?php
// src/Entity/TraficHistorique.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'trafic_historique')]
#[ORM\Index(columns: ['site'], name: 'idx_site')]
#[ORM\Index(columns: ['date_heure'], name: 'idx_date_heure')]
#[ORM\Index(columns: ['site', 'date_heure'], name: 'idx_trafic_site_date_heure')]
class TraficHistorique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $site = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateHeure = null;

    #[ORM\Column(type: 'float')]
    private ?float $maxSpeed = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateImportation = null;

    // Getters et setters
    public function getId(): ?int { return $this->id; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): static { $this->site = $site; return $this; }
    public function getDateHeure(): ?\DateTimeInterface { return $this->dateHeure; }
    public function setDateHeure(\DateTimeInterface $dateHeure): static { $this->dateHeure = $dateHeure; return $this; }
    public function getMaxSpeed(): ?float { return $this->maxSpeed; }
    public function setMaxSpeed(float $maxSpeed): static { $this->maxSpeed = $maxSpeed; return $this; }
    public function getDateImportation(): ?\DateTimeInterface { return $this->dateImportation; }
    public function setDateImportation(?\DateTimeInterface $dateImportation): static { $this->dateImportation = $dateImportation; return $this; }
}
