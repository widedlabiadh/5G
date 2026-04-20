<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        // Rediriger vers le dashboard unique
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
    public function newUser(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $newUser = new User();
        $form = $this->createForm(UserType::class, $newUser, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $newUser->setPassword($hasher->hashPassword($newUser, $plainPassword));
            $newUser->setIsActive(true);
            
            $em->persist($newUser);
            $em->flush();

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
    public function editUser(
        User $targetUser,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $form = $this->createForm(UserType::class, $targetUser, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
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
    public function toggleUser(User $targetUser, EntityManagerInterface $em): Response
    {
        if ($targetUser->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', '❌ Vous ne pouvez pas désactiver votre propre compte !');
            return $this->redirectToRoute('admin_users');
        }

        $targetUser->setIsActive(!$targetUser->isActive());
        $em->flush();

        $status = $targetUser->isActive() ? 'activé' : 'désactivé';
        $this->addFlash('success', "✅ Compte {$status} avec succès !");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(
        User $targetUser,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if ($targetUser->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', '❌ Vous ne pouvez pas supprimer votre propre compte !');
            return $this->redirectToRoute('admin_users');
        }

        $token = $request->request->get('_token');
        
        if (!$this->isCsrfTokenValid('delete' . $targetUser->getId(), $token)) {
            $this->addFlash('error', '❌ Token de sécurité invalide. Veuillez rafraîchir la page et réessayer.');
            return $this->redirectToRoute('admin_users');
        }

        try {
            $userName = $targetUser->getFullName() ?: $targetUser->getEmail();
            $userId = $targetUser->getId();
            
            $em->remove($targetUser);
            $em->flush();
            
            $this->addFlash('success', "✅ L'utilisateur '{$userName}' (ID: {$userId}) a été supprimé avec succès !");
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ Erreur lors de la suppression : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function resetPassword(
        User $targetUser,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reset' . $targetUser->getId(), $token)) {
            $this->addFlash('error', '❌ Token de sécurité invalide. Veuillez rafraîchir la page et réessayer.');
            return $this->redirectToRoute('admin_users');
        }

        $newPassword = $request->request->get('new_password');
        
        if (!$newPassword || strlen($newPassword) < 6) {
            $this->addFlash('error', '❌ Le mot de passe doit contenir au moins 6 caractères !');
            return $this->redirectToRoute('admin_users');
        }

        try {
            $userName = $targetUser->getFullName() ?: $targetUser->getEmail();
            $targetUser->setPassword($hasher->hashPassword($targetUser, $newPassword));
            $em->flush();
            
            $this->addFlash('success', "✅ Mot de passe réinitialisé avec succès pour '{$userName}' !");
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ Erreur lors de la réinitialisation : ' . $e->getMessage());
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
            ->add('site_name', TextType::class, [
                'label' => 'Nom du site',
                'required' => false,
                'data' => $existingSettings['site_name'],
                'attr' => ['class' => 'form-control', 'placeholder' => 'Nom du site']
            ])
            ->add('items_per_page', IntegerType::class, [
                'label' => 'Éléments par page',
                'required' => false,
                'data' => $existingSettings['items_per_page'],
                'attr' => ['class' => 'form-control', 'min' => 5, 'max' => 100]
            ])
            ->add('maintenance_mode', CheckboxType::class, [
                'label' => 'Mode maintenance',
                'required' => false,
                'data' => $existingSettings['maintenance_mode'],
                'attr' => ['class' => 'checkbox-input']
            ])
            ->getForm()
        ;
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $session->set('site_settings', $data);
            
            $this->addFlash('success', '✅ Paramètres sauvegardés avec succès !');
            return $this->redirectToRoute('admin_settings');
        }
        
        return $this->render('admin/settings.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}