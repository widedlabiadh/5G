<?php

namespace App\Entity;

use App\Repository\ManagedSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: ManagedSiteRepository::class)]
#[ORM\Table(name: 'managed_site')]
#[ORM\UniqueConstraint(name: 'uniq_managed_site_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'Un site avec ce nom existe deja.')]
class ManagedSite
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_INACTIVE = 'inactive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $maxTrafic = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $maxTdd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $maxFdd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $capaciteTddMbps = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $capaciteFddMbps = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tauxUtilisation = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreOccurrences = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreOccurrencesTdd = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreOccurrencesFdd = 0;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $decisionStatut = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $etat = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateMax = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $classification = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getMaxTrafic(): ?string
    {
        return $this->maxTrafic;
    }

    public function setMaxTrafic(?string $maxTrafic): self
    {
        $this->maxTrafic = $maxTrafic;
        return $this;
    }

    public function getMaxTdd(): ?string
    {
        return $this->maxTdd;
    }

    public function setMaxTdd(?string $maxTdd): self
    {
        $this->maxTdd = $maxTdd;
        return $this;
    }

    public function getMaxFdd(): ?string
    {
        return $this->maxFdd;
    }

    public function setMaxFdd(?string $maxFdd): self
    {
        $this->maxFdd = $maxFdd;
        return $this;
    }

    public function getCapaciteTddMbps(): ?string
    {
        return $this->capaciteTddMbps;
    }

    public function setCapaciteTddMbps(?string $capaciteTddMbps): self
    {
        $this->capaciteTddMbps = $capaciteTddMbps;
        return $this;
    }

    public function getCapaciteFddMbps(): ?string
    {
        return $this->capaciteFddMbps;
    }

    public function setCapaciteFddMbps(?string $capaciteFddMbps): self
    {
        $this->capaciteFddMbps = $capaciteFddMbps;
        return $this;
    }

    public function getTauxUtilisation(): ?string
    {
        return $this->tauxUtilisation;
    }

    public function setTauxUtilisation(?string $tauxUtilisation): self
    {
        $this->tauxUtilisation = $tauxUtilisation;
        return $this;
    }

    public function getNombreOccurrences(): int
    {
        return $this->nombreOccurrences;
    }

    public function setNombreOccurrences(int $nombreOccurrences): self
    {
        $this->nombreOccurrences = $nombreOccurrences;
        return $this;
    }

    public function getNombreOccurrencesTdd(): int
    {
        return $this->nombreOccurrencesTdd;
    }

    public function setNombreOccurrencesTdd(int $nombreOccurrencesTdd): self
    {
        $this->nombreOccurrencesTdd = $nombreOccurrencesTdd;
        return $this;
    }

    public function getNombreOccurrencesFdd(): int
    {
        return $this->nombreOccurrencesFdd;
    }

    public function setNombreOccurrencesFdd(int $nombreOccurrencesFdd): self
    {
        $this->nombreOccurrencesFdd = $nombreOccurrencesFdd;
        return $this;
    }

    public function getDecisionStatut(): ?string
    {
        return $this->decisionStatut;
    }

    public function setDecisionStatut(?string $decisionStatut): self
    {
        $this->decisionStatut = $decisionStatut;
        return $this;
    }

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function setEtat(?string $etat): self
    {
        $this->etat = $etat;
        return $this;
    }

    public function getDateMax(): ?\DateTimeInterface
    {
        return $this->dateMax;
    }

    public function setDateMax(?\DateTimeInterface $dateMax): self
    {
        $this->dateMax = $dateMax;
        return $this;
    }

    public function getTypeTrans(): ?string
    {
        return $this->typeTrans;
    }

    public function setTypeTrans(?string $typeTrans): self
    {
        $this->typeTrans = $typeTrans;
        return $this;
    }

    public function getClassification(): ?string
    {
        return $this->classification;
    }

    public function setClassification(?string $classification): self
    {
        $this->classification = $classification;
        return $this;
    }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
