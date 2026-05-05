<?php
// src/Entity/TypeLiaisonData.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'type_liaison_data')]
#[ORM\Index(columns: ['site'], name: 'idx_typen_site')]
class TypeLiaisonData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $site = null;

    #[ORM\Column(length: 100)]
    private ?string $typeTrans = null;

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
    
    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(string $typeTrans): self { $this->typeTrans = $typeTrans; return $this; }
    
    public function getDateImport(): ?\DateTimeInterface { return $this->dateImport; }
    
    // ⚠️ AJOUTER CE SETTER MANQUANT ⚠️
    public function setDateImport(\DateTimeInterface $dateImport): self
    {
        $this->dateImport = $dateImport;
        return $this;
    }
}