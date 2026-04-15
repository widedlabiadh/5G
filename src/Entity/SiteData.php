<?php
// src/Entity/SiteData.php

namespace App\Entity;

use App\Repository\SiteDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteDataRepository::class)]
#[ORM\Table(name: 'site_data')]
class SiteData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $enode_name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $classification = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type_liaison = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $max_trafic = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $seuil_critique = null;

    #[ORM\Column(nullable: true)]
    private ?int $nombre_occurrences = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updated_at = null;

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getEnodeName(): ?string { return $this->enode_name; }
    public function getClassification(): ?string { return $this->classification; }
    public function getTypeLiaison(): ?string { return $this->type_liaison; }
    public function getMaxTrafic(): ?string { return $this->max_trafic; }
    public function getSeuilCritique(): ?string { return $this->seuil_critique; }
    public function getNombreOccurrences(): ?int { return $this->nombre_occurrences; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updated_at; }

    // Setters
    public function setEnodeName(string $enode_name): self { $this->enode_name = $enode_name; return $this; }
    public function setClassification(?string $classification): self { $this->classification = $classification; return $this; }
    public function setTypeLiaison(?string $type_liaison): self { $this->type_liaison = $type_liaison; return $this; }
    public function setMaxTrafic(?string $max_trafic): self { $this->max_trafic = $max_trafic; return $this; }
    public function setSeuilCritique(?string $seuil_critique): self { $this->seuil_critique = $seuil_critique; return $this; }
    public function setNombreOccurrences(?int $nombre_occurrences): self { $this->nombre_occurrences = $nombre_occurrences; return $this; }
    public function setUpdatedAt(?\DateTimeInterface $updated_at): self { $this->updated_at = $updated_at; return $this; }
}