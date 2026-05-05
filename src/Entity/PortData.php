<?php
// src/Entity/PortData.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'port_data')]
#[ORM\Index(columns: ['site'], name: 'idx_port_site')]
class PortData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $site = null;

    #[ORM\Column]
    private ?int $portNo = null;

    #[ORM\Column(type: 'float')]
    private ?float $trafic = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateImport = null;

    public function __construct()
    {
        $this->dateImport = new \DateTime();
    }

    // Getters et setters
    public function getId(): ?int { return $this->id; }
    
    public function getSite(): ?string { return $this->site; }
    public function setSite(string $site): self { $this->site = $site; return $this; }
    
    public function getPortNo(): ?int { return $this->portNo; }
    public function setPortNo(int $portNo): self { $this->portNo = $portNo; return $this; }
    
    public function getTrafic(): ?float { return $this->trafic; }
    public function setTrafic(float $trafic): self { $this->trafic = $trafic; return $this; }
    
    public function getDateImport(): ?\DateTimeInterface { return $this->dateImport; }
    
    // ⚠️ AJOUTER CE SETTER MANQUANT ⚠️
    public function setDateImport(\DateTimeInterface $dateImport): self
    {
        $this->dateImport = $dateImport;
        return $this;
    }
}