<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private UserManager $userManager;

    public function __construct(UserManager $userManager)
    {
        $this->userManager = $userManager;
    }

    #[Route('/dashboard', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/users', name: 'admin_users')]
    public function users(UserRepository $userRepository): Response
    {
        return $this->render('admin/users.html.twig', [
            'user'  => $this->getUser(),
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/users/new', name: 'admin_user_new')]
    public function newUser(Request $request, EntityManagerInterface $em): Response
    {
        $newUser = new User();
        $form = $this->createForm(UserType::class, $newUser, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $this->userManager->createUser($newUser, $plainPassword);
            $this->addFlash('success', '✅ Utilisateur créé avec succès !');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user'    => $this->getUser(),
            'form'    => $form->createView(),
            'isNew'   => true,
            'title'   => 'Ajouter un utilisateur',
        ]);
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit')]
    public function editUser(User $targetUser, Request $request): Response
    {
        $form = $this->createForm(UserType::class, $targetUser, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userManager->updateUser($targetUser);
            $this->addFlash('success', '✅ Utilisateur modifié avec succès !');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user'       => $this->getUser(),
            'form'       => $form->createView(),
            'isEdit'     => true,
            'title'      => 'Modifier ' . $targetUser->getFullName(),
            'targetUser' => $targetUser,
        ]);
    }

    #[Route('/users/{id}/toggle', name: 'admin_user_toggle')]
    public function toggleUser(User $targetUser): Response
    {
        if ($this->userManager->toggleUser($targetUser, $this->getUser())) {
            $status = $targetUser->isActive() ? 'activé' : 'désactivé';
            $this->addFlash('success', "✅ Compte {$status} avec succès !");
        } else {
            $this->addFlash('error', '❌ Vous ne pouvez pas désactiver votre propre compte !');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $targetUser, Request $request): Response
    {
        $token = $request->request->get('_token');
        if ($this->userManager->deleteUser($targetUser, $this->getUser(), $token, 'delete' . $targetUser->getId())) {
            $this->addFlash('success', "✅ Utilisateur '{$targetUser->getFullName()}' supprimé !");
        } else {
            $this->addFlash('error', '❌ Suppression impossible (compte personnel ou token invalide).');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function resetPassword(User $targetUser, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reset' . $targetUser->getId(), $token)) {
            $this->addFlash('error', '❌ Token invalide.');
            return $this->redirectToRoute('admin_users');
        }
        $newPassword = $request->request->get('new_password');
        if ($this->userManager->resetPassword($targetUser, $newPassword)) {
            $this->addFlash('success', "✅ Mot de passe réinitialisé !");
        } else {
            $this->addFlash('error', '❌ Mot de passe trop court (min 6 caractères).');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/settings', name: 'admin_settings')]
    public function settings(Request $request): Response
    {
        $user = $this->getUser();
        $session = $request->getSession();
        $existingSettings = $session->get('site_settings', [
            'site_name' => 'NetWON',
            'items_per_page' => 10,
            'maintenance_mode' => false
        ]);

        $form = $this->createFormBuilder()
            ->add('site_name', TextType::class, ['label' => 'Nom du site', 'required' => false, 'data' => $existingSettings['site_name']])
            ->add('items_per_page', IntegerType::class, ['label' => 'Éléments par page', 'required' => false, 'data' => $existingSettings['items_per_page']])
            ->add('maintenance_mode', CheckboxType::class, ['label' => 'Mode maintenance', 'required' => false, 'data' => $existingSettings['maintenance_mode']])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('site_settings', $form->getData());
            $this->addFlash('success', '✅ Paramètres sauvegardés avec succès !');
            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}