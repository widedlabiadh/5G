<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class UserManager
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private CsrfTokenManagerInterface $csrf;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, CsrfTokenManagerInterface $csrf)
    {
        $this->em = $em;
        $this->hasher = $hasher;
        $this->csrf = $csrf;
    }

    public function createUser(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
    }

    public function updateUser(User $user): void
    {
        $this->em->flush();
    }

    public function toggleUser(User $user, User $currentUser): bool
    {
        if ($user->getId() === $currentUser->getId()) {
            return false;
        }
        $user->setIsActive(!$user->isActive());
        $this->em->flush();
        return true;
    }

    public function resetPassword(User $user, string $newPassword): bool
    {
        if (strlen($newPassword) < 6) {
            return false;
        }
        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $this->em->flush();
        return true;
    }

    public function deleteUser(User $user, User $currentUser, string $csrfToken, string $tokenId): bool
    {
        if ($user->getId() === $currentUser->getId()) {
            return false;
        }
        if (!$this->csrf->getToken($tokenId)->getValue() === $csrfToken) {
            return false;
        }
        $this->em->remove($user);
        $this->em->flush();
        return true;
    }
}
