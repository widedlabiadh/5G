<?php
// src/Entity/SiteAlert.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_alert')]
#[ORM\Index(columns: ['site'], name: 'idx_alert_site')]
#[ORM\Index(columns: ['date_alerte'], name: 'idx_alert_date')]
#[ORM\Index(columns: ['alerte_lue'], name: 'idx_alert_lue')]
class SiteAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $site = null;

    #[ORM\Column(length: 20)]
    private ?string $etat = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $traficJ = 0;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, options: ['default' => 0])]
    private ?float $capaciteMbps = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $tauxUtilisation = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $variationJ1 = 0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, options: ['default' => 0])]
    private ?float $variationJ7 = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $classification = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $dateAlerte = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $alerteLue = false;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $emailEnvoye = false;

    public function __construct()
    {
        $this->dateAlerte = new \DateTime();
    }

    // Getters & Setters
    public function getId(): ?int { return $this->id; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): self { $this->site = $site; return $this; }
    public function getEtat(): ?string { return $this->etat; }
    public function setEtat(string $etat): self { $this->etat = $etat; return $this; }
    public function getTraficJ(): ?float { return $this->traficJ; }
    public function setTraficJ(float $traficJ): self { $this->traficJ = $traficJ; return $this; }
    public function getCapaciteMbps(): ?float { return $this->capaciteMbps; }
    public function setCapaciteMbps(float $capaciteMbps): self { $this->capaciteMbps = $capaciteMbps; return $this; }
    public function getTauxUtilisation(): ?float { return $this->tauxUtilisation; }
    public function setTauxUtilisation(float $tauxUtilisation): self { $this->tauxUtilisation = $tauxUtilisation; return $this; }
    public function getVariationJ1(): ?float { return $this->variationJ1; }
    public function setVariationJ1(float $variationJ1): self { $this->variationJ1 = $variationJ1; return $this; }
    public function getVariationJ7(): ?float { return $this->variationJ7; }
    public function setVariationJ7(float $variationJ7): self { $this->variationJ7 = $variationJ7; return $this; }
    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(?string $typeTrans): self { $this->typeTrans = $typeTrans; return $this; }
    public function getClassification(): ?string { return $this->classification; }
    public function setClassification(?string $classification): self { $this->classification = $classification; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
    public function getDateAlerte(): ?\DateTimeInterface { return $this->dateAlerte; }
    public function setDateAlerte(\DateTimeInterface $dateAlerte): self { $this->dateAlerte = $dateAlerte; return $this; }
    public function isAlerteLue(): ?bool { return $this->alerteLue; }
    public function setAlerteLue(bool $alerteLue): self { $this->alerteLue = $alerteLue; return $this; }
    public function isEmailEnvoye(): ?bool { return $this->emailEnvoye; }
    public function setEmailEnvoye(bool $emailEnvoye): self { $this->emailEnvoye = $emailEnvoye; return $this; }
}