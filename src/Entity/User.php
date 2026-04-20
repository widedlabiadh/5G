<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    // ============================================================
    // CONSTANTES (ROLES LABELS uniquement)
    // ============================================================

    const ROLE_LABELS = [
        'admin'         => 'Administrateur',
        'superuser'     => 'Super Utilisateur',
        'support_fo'    => 'Support FO',
        'support_fh'    => 'Support FH',
        'ingenierie_fh' => 'Ingénierie FH',
        'ingenierie_ip' => 'Ingénierie IP',
        'gc'            => 'Département GC',
        'deploiement'   => 'Déploiement',
        'controleur'    => 'Contrôleur',
    ];

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 30)]
    private string $role = 'support_fh';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    // ============================================================
    // LIFECYCLE CALLBACKS
    // ============================================================

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ============================================================
    // GETTERS & SETTERS
    // ============================================================

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // ============================================================
    // SYMFONY SECURITY INTERFACE
    // ============================================================

    public function getRoles(): array
    {
        return ['ROLE_' . strtoupper($this->role)];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void {}

    // ============================================================
    // MÉTHODES UTILITAIRES
    // ============================================================

    public function getRoleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }
    
    public function isControleur(): bool
    {
        return $this->role === 'controleur';
    }
    
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superuser']);
    }
}