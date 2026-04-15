<?php
// src/Controller/SecurityController.php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser()) {
            /** @var User $user */
            $user = $this->getUser();

            // ✅ Ajout de ROLE_CONTROLEUR et ROLE_INGENIEUR
            if ($user->getRole() === 'admin') {
                return $this->redirectToRoute('admin_dashboard');
            }
            if ($user->getRole() === 'superuser') {
                return $this->redirectToRoute('superuser_dashboard');
            }
            if ($user->getRole() === 'controleur') {
                return $this->redirectToRoute('controleur_dashboard');
            }
            if ($user->getRole() === 'ingenieur') {
                return $this->redirectToRoute('ingenieur_dashboard');
            }
            return $this->redirectToRoute('app_checklist');
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            /** @var User $user */
            $user = $this->getUser();

            // ✅ Ajout de ROLE_CONTROLEUR et ROLE_INGENIEUR
            if ($user->getRole() === 'ROLE_ADMIN') {
                return $this->redirectToRoute('admin_dashboard');
            }
            if ($user->getRole() === 'ROLE_SUPERUSER') {
                return $this->redirectToRoute('superuser_dashboard');
            }
            if ($user->getRole() === 'ROLE_CONTROLEUR') {
                return $this->redirectToRoute('controleur_dashboard');
            }
            if ($user->getRole() === 'ROLE_INGENIEUR') {
                return $this->redirectToRoute('ingenieur_dashboard');
            }
            return $this->redirectToRoute('app_checklist');
        }

        return $this->render('security/login.html.twig', [
            'error'         => $authUtils->getLastAuthenticationError(),
            'last_username' => $authUtils->getLastUsername(),
        ]);
    }

    #[Route('/checklist', name: 'app_checklist')]
    public function checklist(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Rôles qui vont directement au dashboard sans checklist
        $rolesDirectDashboard = ['ROLE_ADMIN', 'ROLE_SUPERUSER', 'ROLE_CONTROLEUR', 'ROLE_INGENIEUR'];

        if (in_array($user->getRole(), $rolesDirectDashboard)) {
            return $this->redirectToRoute('app_dashboard');
        }

        if (!$user->needsChecklist()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $selected = $request->request->get('profile');
            $request->getSession()->set('selected_profile', $selected);
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/checklist.html.twig', [
            'role'      => $user->getRole(),
            'roleLabel' => $user->getRoleLabel(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void {}
}