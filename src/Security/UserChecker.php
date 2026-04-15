<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Vérifier si le compte est actif
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(
                '❌ Votre compte a été désactivé. Veuillez contacter l\'administrateur.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Vérification supplémentaire après authentification
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(
                '❌ Votre compte est désactivé. Accès refusé.'
            );
        }
    }
}