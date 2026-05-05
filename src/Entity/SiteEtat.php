<?php
// src/Entity/SiteEtat.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_etat')]
#[ORM\Index(columns: ['etat'], name: 'idx_site_etat_etat')]
class SiteEtat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $site = null;

    #[ORM\Column(length: 20, options: ['default' => 'OK'])]
    private ?string $etat = 'OK';

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $traficJ = 0;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $traficJ1 = 0;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $traficJ7 = 0;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $capaciteMbps = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $tauxUtilisation = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $variationJ1 = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $variationJ7 = 0;

    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $dateCalcul = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateJ = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $alerteEnvoyee = false;

    public function __construct()
    {
        $this->dateCalcul = new \DateTime();
    }

    // Getters & Setters (génère-les automatiquement avec make:entity)
    public function getId(): ?int { return $this->id; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): self { $this->site = $site; return $this; }
    public function getEtat(): ?string { return $this->etat; }
    public function setEtat(string $etat): self { $this->etat = $etat; return $this; }
    public function getTraficJ(): ?float { return $this->traficJ; }
    public function setTraficJ(float $traficJ): self { $this->traficJ = $traficJ; return $this; }
    public function getTraficJ1(): ?float { return $this->traficJ1; }
    public function setTraficJ1(float $traficJ1): self { $this->traficJ1 = $traficJ1; return $this; }
    public function getTraficJ7(): ?float { return $this->traficJ7; }
    public function setTraficJ7(float $traficJ7): self { $this->traficJ7 = $traficJ7; return $this; }
    public function getCapaciteMbps(): ?float { return $this->capaciteMbps; }
    public function setCapaciteMbps(float $capaciteMbps): self { $this->capaciteMbps = $capaciteMbps; return $this; }
    public function getTauxUtilisation(): ?float { return $this->tauxUtilisation; }
    public function setTauxUtilisation(float $tauxUtilisation): self { $this->tauxUtilisation = $tauxUtilisation; return $this; }
    public function getVariationJ1(): ?float { return $this->variationJ1; }
    public function setVariationJ1(float $variationJ1): self { $this->variationJ1 = $variationJ1; return $this; }
    public function getVariationJ7(): ?float { return $this->variationJ7; }
    public function setVariationJ7(float $variationJ7): self { $this->variationJ7 = $variationJ7; return $this; }
    public function getDateCalcul(): ?\DateTimeInterface { return $this->dateCalcul; }
    public function setDateCalcul(\DateTimeInterface $dateCalcul): self { $this->dateCalcul = $dateCalcul; return $this; }
    public function getDateJ(): ?\DateTimeInterface { return $this->dateJ; }
    public function setDateJ(?\DateTimeInterface $dateJ): self { $this->dateJ = $dateJ; return $this; }
    public function isAlerteEnvoyee(): ?bool { return $this->alerteEnvoyee; }
    public function setAlerteEnvoyee(bool $alerteEnvoyee): self { $this->alerteEnvoyee = $alerteEnvoyee; return $this; }
}