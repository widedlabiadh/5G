<?php
// src/Entity/AnalyseResultat.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analyse_resultat')]
class AnalyseResultat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $site = null;

    #[ORM\Column(length: 50)]
    private ?string $classification = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private ?float $maxTrafic = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private ?float $seuilCritique = null;

    #[ORM\Column]
    private ?int $nombreOccurrences = 0;

    #[ORM\Column]
    private ?int $totalMeasures = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateMax = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $siteTdd = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $siteFdd = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $sommeFddTdd = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateAnalyse = null;

    // ============================================================
    // PROPRIÉTÉS POUR LA CARTE GPS
    // ============================================================
    
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    // ============================================================
    // PROPRIÉTÉ POUR LA CAPACITÉ (Mbps)
    // ============================================================
    
    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?float $capaciteMbps = null;

    // ============================================================
    // GETTERS ET SETTERS
    // ============================================================
    
    public function getId(): ?int { return $this->id; }
    
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): self { $this->site = $site; return $this; }
    
    public function getClassification(): ?string { return $this->classification; }
    public function setClassification(string $classification): self { $this->classification = $classification; return $this; }
    
    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(?string $typeTrans): self { $this->typeTrans = $typeTrans; return $this; }
    
    public function getMaxTrafic(): ?float { return $this->maxTrafic; }
    public function setMaxTrafic(float $maxTrafic): self { $this->maxTrafic = $maxTrafic; return $this; }
    
    public function getSeuilCritique(): ?float { return $this->seuilCritique; }
    public function setSeuilCritique(float $seuilCritique): self { $this->seuilCritique = $seuilCritique; return $this; }
    
    public function getNombreOccurrences(): ?int { return $this->nombreOccurrences; }
    public function setNombreOccurrences(int $nombreOccurrences): self { $this->nombreOccurrences = $nombreOccurrences; return $this; }
    
    public function getTotalMeasures(): ?int { return $this->totalMeasures; }
    public function setTotalMeasures(int $totalMeasures): self { $this->totalMeasures = $totalMeasures; return $this; }
    
    public function getDateMax(): ?\DateTimeInterface { return $this->dateMax; }
    public function setDateMax(?\DateTimeInterface $dateMax): self { $this->dateMax = $dateMax; return $this; }
    
    public function getSiteTdd(): ?string { return $this->siteTdd; }
    public function setSiteTdd(?string $siteTdd): self { $this->siteTdd = $siteTdd; return $this; }
    
    public function getSiteFdd(): ?string { return $this->siteFdd; }
    public function setSiteFdd(?string $siteFdd): self { $this->siteFdd = $siteFdd; return $this; }
    
    public function getSommeFddTdd(): ?float { return $this->sommeFddTdd; }
    public function setSommeFddTdd(?float $sommeFddTdd): self { $this->sommeFddTdd = $sommeFddTdd; return $this; }
    
    public function getDateAnalyse(): ?\DateTimeInterface { return $this->dateAnalyse; }
    public function setDateAnalyse(\DateTimeInterface $dateAnalyse): self { $this->dateAnalyse = $dateAnalyse; return $this; }

    // ============================================================
    // GETTERS ET SETTERS POUR GPS
    // ============================================================
    
    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;
        return $this;
    }

    // ============================================================
    // GETTERS ET SETTERS POUR CAPACITE_Mbps
    // ============================================================
    
    public function getCapaciteMbps(): ?float
    {
        return $this->capaciteMbps;
    }

    public function setCapaciteMbps(?float $capaciteMbps): self
    {
        $this->capaciteMbps = $capaciteMbps;
        return $this;
    }
}