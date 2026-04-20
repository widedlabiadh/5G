<?php
// src/Controller/DashboardController.php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AnalyseResultat;
use App\Entity\TicketTask;
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

class DashboardController extends AbstractController
{
    // ============================================================
    // HELPERS PRIVÉS
    // ============================================================

    /**
     * Récupère la dernière date d'analyse unique
     */
    private function getLastAnalyseDate(EntityManagerInterface $em): ?string
    {
        $conn = $em->getConnection();
        $result = $conn->fetchOne(
            'SELECT DISTINCT date_analyse FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
        );
        return $result ?: null;
    }

    private function getStatsFromDatabase(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        
        $lastDate = $this->getLastAnalyseDate($em);
        
        if (!$lastDate) {
            return [
                'total_sites' => 0,
                'sites_tf' => 0,
                'sites_cotrans' => 0,
                'sites_nocotrans' => 0,
                'sites_nontdd' => 0,
                'total_occurrences' => 0,
                'fo' => 0,
                'fh' => 0,
                'backbone' => 0,
                'sans_type' => 0,
                'fo_congestions' => 0,
                'fh_congestions' => 0,
                'backbone_congestions' => 0,
                'max_trafic_fo' => 0,
                'max_trafic_fh' => 0,
                'max_trafic_backbone' => 0,
            ];
        }

        $totalSites       = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE date_analyse = :lastDate', ['lastDate' => $lastDate]);
        $sitesTF          = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = :type AND date_analyse = :lastDate', ['type' => 'TF', 'lastDate' => $lastDate]);
        $sitesCOTRANS     = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = :type AND date_analyse = :lastDate', ['type' => 'COTRANS', 'lastDate' => $lastDate]);
        $sitesNOCOTRANS   = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = :type AND date_analyse = :lastDate', ['type' => 'NO_COTRANS', 'lastDate' => $lastDate]);
        $sitesNON_TDD     = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = :type AND date_analyse = :lastDate', ['type' => 'NON_TDD', 'lastDate' => $lastDate]);
        $totalOccurrences = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE date_analyse = :lastDate', ['lastDate' => $lastDate]);
        $fo               = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FO', 'lastDate' => $lastDate]);
        $fh               = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FH', 'lastDate' => $lastDate]);
        $backbone         = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'BACKBONE', 'lastDate' => $lastDate]);
        $sansType         = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'NON_DEFINI', 'lastDate' => $lastDate]);
        $foCongestions    = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FO', 'lastDate' => $lastDate]);
        $fhCongestions    = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FH', 'lastDate' => $lastDate]);
        $backboneCongestions = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'BACKBONE', 'lastDate' => $lastDate]);
        $maxTraficFO      = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FO', 'lastDate' => $lastDate]);
        $maxTraficFH      = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'FH', 'lastDate' => $lastDate]);
        $maxTraficBackbone = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate', ['type' => 'BACKBONE', 'lastDate' => $lastDate]);

        return [
            'total_sites'          => (int)$totalSites,
            'sites_tf'             => (int)$sitesTF,
            'sites_cotrans'        => (int)$sitesCOTRANS,
            'sites_nocotrans'      => (int)$sitesNOCOTRANS,
            'sites_nontdd'         => (int)$sitesNON_TDD,
            'total_occurrences'    => (int)$totalOccurrences,
            'fo'                   => (int)$fo,
            'fh'                   => (int)$fh,
            'backbone'             => (int)$backbone,
            'sans_type'            => (int)$sansType,
            'fo_congestions'       => (int)$foCongestions,
            'fh_congestions'       => (int)$fhCongestions,
            'backbone_congestions' => (int)$backboneCongestions,
            'max_trafic_fo'        => (float)$maxTraficFO,
            'max_trafic_fh'        => (float)$maxTraficFH,
            'max_trafic_backbone'  => (float)$maxTraficBackbone,
        ];
    }

    private function getSitesCritiques(EntityManagerInterface $em, int $limit = 20): array
    {
        $conn = $em->getConnection();
        
        $lastDate = $this->getLastAnalyseDate($em);
        
        if (!$lastDate) {
            return [];
        }
        
        $results = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat
             WHERE nombre_occurrences > 50 AND date_analyse = :lastDate
             ORDER BY nombre_occurrences DESC
             LIMIT :limit',
            ['lastDate' => $lastDate, 'limit' => $limit]
        );

        $sites = [];
        foreach ($results as $row) {
            $site = new AnalyseResultat();
            $site->setSite($row['site']);
            $site->setClassification($row['classification']);
            $site->setTypeTrans($row['type_trans']);
            $site->setMaxTrafic((float)$row['max_trafic']);
            $site->setSeuilCritique((float)$row['seuil_critique']);
            $site->setNombreOccurrences((int)$row['nombre_occurrences']);
            $site->setTotalMeasures((int)$row['total_measures']);
            $site->setDateAnalyse(new \DateTime($row['date_analyse']));
            $sites[] = $site;
        }

        return $sites;
    }

    private function getDerniereAnalyse(EntityManagerInterface $em): ?AnalyseResultat
    {
        $conn = $em->getConnection();
        $lastDate = $this->getLastAnalyseDate($em);
        
        if (!$lastDate) return null;
        
        $row  = $conn->fetchAssociative(
            'SELECT * FROM analyse_resultat WHERE date_analyse = :lastDate LIMIT 1',
            ['lastDate' => $lastDate]
        );

        if (!$row) return null;

        $site = new AnalyseResultat();
        $site->setSite($row['site']);
        $site->setClassification($row['classification']);
        $site->setTypeTrans($row['type_trans']);
        $site->setMaxTrafic((float)$row['max_trafic']);
        $site->setSeuilCritique((float)$row['seuil_critique']);
        $site->setNombreOccurrences((int)$row['nombre_occurrences']);
        $site->setTotalMeasures((int)$row['total_measures']);
        $site->setDateAnalyse(new \DateTime($row['date_analyse']));

        return $site;
    }

    private function getSitesForGps(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        
        $lastDate = $this->getLastAnalyseDate($em);
        
        if (!$lastDate) {
            return [];
        }
        
        $results = $conn->fetchAllAssociative(
            'SELECT id, site, classification, type_trans, max_trafic, seuil_critique, nombre_occurrences, latitude, longitude, date_analyse 
             FROM analyse_resultat 
             WHERE date_analyse = :lastDate
             ORDER BY site',
            ['lastDate' => $lastDate]
        );

        $sites = [];
        foreach ($results as $row) {
            $occurrences = (int)$row['nombre_occurrences'];
            if ($occurrences > 50) {
                $statut = 'critique';
            } elseif ($occurrences > 20) {
                $statut = 'sous_observation';
            } elseif ($occurrences > 0) {
                $statut = 'securise';
            } else {
                $statut = 'non_defini';
            }

            $latitude = $row['latitude'];
            $longitude = $row['longitude'];
            
            if ($latitude === null || $longitude === null || $latitude == 0 || $longitude == 0) {
                $coords = $this->generateCoordinatesFromSiteName($row['site']);
                $latitude = $coords['lat'];
                $longitude = $coords['lng'];
            }

            $sites[] = [
                'id' => $row['id'],
                'nom' => $row['site'],
                'code' => $row['site'],
                'classification' => $row['classification'],
                'typeTrans' => $row['type_trans'] ?? 'NON_DEFINI',
                'maxTrafic' => (float)$row['max_trafic'],
                'seuilCritique' => (float)$row['seuil_critique'],
                'nbOccurrences' => $occurrences,
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'statut' => $statut,
                'estimation2026' => $this->calculateEstimation($occurrences, (float)$row['max_trafic'])
            ];
        }

        return $sites;
    }

    private function generateCoordinatesFromSiteName(string $siteName): array
    {
        $governorates = [
            'TUNIS' => [36.8065, 10.1815],
            'ARIANA' => [36.8601, 10.1934],
            'BEN AROUS' => [36.7539, 10.2186],
            'MANOUBA' => [36.8081, 10.0956],
            'NABEUL' => [36.4561, 10.7376],
            'ZAGHOUAN' => [36.4029, 10.1427],
            'BIZERTE' => [37.2744, 9.8739],
            'BEJA' => [36.7256, 9.1817],
            'JENDOUBA' => [36.5014, 8.7803],
            'LE KEF' => [36.1741, 8.7049],
            'SILIANA' => [36.0850, 9.3705],
            'SOUSSE' => [35.8256, 10.6364],
            'MONASTIR' => [35.7779, 10.8262],
            'MAHDIA' => [35.5041, 11.0622],
            'SFAX' => [34.7406, 10.7603],
            'KAIROUAN' => [35.6781, 10.0964],
            'KASSERINE' => [35.1676, 8.8365],
            'SIDI BOUZID' => [35.0382, 9.4858],
            'GABES' => [33.8815, 10.0982],
            'MEDENINE' => [33.3548, 10.5055],
            'TATAOUINE' => [32.9296, 10.4518],
            'GAFSA' => [34.4250, 8.7842],
            'TOZEUR' => [33.9197, 8.1336],
            'KEBILI' => [33.7044, 8.9690],
        ];

        $siteNameUpper = strtoupper($siteName);
        
        foreach ($governorates as $gov => $coords) {
            if (strpos($siteNameUpper, $gov) !== false) {
                $lat = $coords[0] + (rand(-50, 50) / 1000);
                $lng = $coords[1] + (rand(-50, 50) / 1000);
                return ['lat' => $lat, 'lng' => $lng];
            }
        }
        
        return ['lat' => 35.6781 + (rand(-100, 100) / 1000), 'lng' => 10.0964 + (rand(-100, 100) / 1000)];
    }

    private function calculateEstimation(int $occurrences, float $maxTrafic): int
    {
        if ($occurrences > 50) return 25;
        if ($occurrences > 20) return 15;
        if ($occurrences > 0) return 8;
        if ($maxTrafic > 500) return 10;
        return 3;
    }

    private function getUserStats(UserRepository $userRepository): array
    {
        $users         = $userRepository->findAll();
        $totalUsers    = count($users);
        $activeUsers   = count(array_filter($users, fn($u) => $u->isActive()));
        $inactiveUsers = $totalUsers - $activeUsers;
        $roles         = array_unique(array_filter(array_map(fn($u) => $u->getRole(), $users)));
        $roleCount     = count($roles);

        return [
            'users'         => $users,
            'totalUsers'    => $totalUsers,
            'activeUsers'   => $activeUsers,
            'inactiveUsers' => $inactiveUsers,
            'roleCount'     => $roleCount,
        ];
    }

    private function getSitesByType(EntityManagerInterface $em, string $type): array
    {
        $conn = $em->getConnection();
        $lastDate = $this->getLastAnalyseDate($em);
        
        if (!$lastDate) {
            return [];
        }
        
        return $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat WHERE type_trans = :type AND date_analyse = :lastDate ORDER BY nombre_occurrences DESC LIMIT 50',
            ['type' => $type, 'lastDate' => $lastDate]
        );
    }

    // ============================================================
    // ROUTE PRINCIPALE — DASHBOARD UNIQUE
    // ============================================================

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $role = $user->getRole();
        
        $stats = $this->getStatsFromDatabase($em);
        $sitesCritiques = $this->getSitesCritiques($em, 20);
        $sitesGps = $this->getSitesForGps($em);
        $derniereAnalyse = $this->getDerniereAnalyse($em);
        
        $specificData = [];
        
        switch ($role) {
            case 'admin':
                $userStats = $this->getUserStats($userRepository);
                $specificData = [
                    'users' => $userStats['users'],
                    'totalUsers' => $userStats['totalUsers'],
                    'activeUsers' => $userStats['activeUsers'],
                    'inactiveUsers' => $userStats['inactiveUsers'],
                    'roleCount' => $userStats['roleCount'],
                ];
                break;
                
            case 'superuser':
                break;
                
            case 'controleur':
                $tickets = $em->getConnection()->fetchAllAssociative(
                    'SELECT t.*, u.full_name as created_by_name
                     FROM ticket t
                     LEFT JOIN "user" u ON t.created_by_id = u.id
                     ORDER BY t.created_at DESC
                     LIMIT 20'
                );
                $specificData = [
                    'tickets' => $tickets,
                    'ticketsActifs' => count($tickets),
                    'ticketsOverdue' => 0,
                ];
                break;
                
            case 'support_fo':
            case 'support_fh':
                $typeTrans = ($role == 'support_fo') ? 'FO' : 'FH';
                
                // Récupérer les tâches de l'utilisateur via le repository
                $taskRepository = $em->getRepository(TicketTask::class);
                $tasks = $taskRepository->findBy(['assignedTo' => $user]);
                
                $myPendingTasks = [];
                $myInProgressTasks = [];
                $myCompletedTasks = [];
                $myOverdueTasks = [];
                
                foreach ($tasks as $task) {
                    if ($task->getStatus() === 'pending') {
                        $myPendingTasks[] = $task;
                    } elseif ($task->getStatus() === 'in_progress') {
                        $myInProgressTasks[] = $task;
                        if ($task->getTicket()->isOverdue()) {
                            $myOverdueTasks[] = $task;
                        }
                    } elseif ($task->getStatus() === 'done') {
                        $myCompletedTasks[] = $task;
                    }
                }
                
                $specificData = [
                    'typeTransmission' => $typeTrans,
                    'sitesType' => $this->getSitesByType($em, $typeTrans),
                    'myPendingTasks' => $myPendingTasks,
                    'myInProgressTasks' => $myInProgressTasks,
                    'myCompletedTasks' => $myCompletedTasks,
                    'myOverdueTasks' => $myOverdueTasks,
                ];
                break;
                
            case 'ingenierie_fh':
            case 'ingenierie_ip':
                // Récupérer les tâches de l'utilisateur via le repository
                $taskRepository = $em->getRepository(TicketTask::class);
                $tasks = $taskRepository->findBy(['assignedTo' => $user]);
                
                $myPendingTasks = [];
                $myInProgressTasks = [];
                $myCompletedTasks = [];
                $myOverdueTasks = [];
                
                foreach ($tasks as $task) {
                    if ($task->getStatus() === 'pending') {
                        $myPendingTasks[] = $task;
                    } elseif ($task->getStatus() === 'in_progress') {
                        $myInProgressTasks[] = $task;
                        if ($task->getTicket()->isOverdue()) {
                            $myOverdueTasks[] = $task;
                        }
                    } elseif ($task->getStatus() === 'done') {
                        $myCompletedTasks[] = $task;
                    }
                }
                
                $specificData = [
                    'typeIngenierie' => ($role == 'ingenierie_fh') ? 'FH' : 'IP',
                    'myPendingTasks' => $myPendingTasks,
                    'myInProgressTasks' => $myInProgressTasks,
                    'myCompletedTasks' => $myCompletedTasks,
                    'myOverdueTasks' => $myOverdueTasks,
                ];
                break;
                
            case 'gc':
                $specificData = ['sitesGC' => []];
                break;
                
            case 'deploiement':
                $specificData = ['sitesDeploiement' => []];
                break;
        }
        
        return $this->render('dashboard/index.html.twig', array_merge([
            'user' => $user,
            'role' => $role,
            'roleLabel' => $user->getRoleLabel(),
            'stats' => $stats,
            'sites_critiques' => $sitesCritiques,
            'sites_gps' => $sitesGps,
            'derniere_analyse' => $derniereAnalyse,
        ], $specificData));
    }

    // ============================================================
    // IMPORT EXPORT
    // ============================================================

    #[Route('/import/excel', name: 'import_excel')]
    public function importExcel(): Response
    {
        return $this->render('import/import.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/export/trafic', name: 'export_trafic_form')]
    public function exportTraficForm(): Response
    {
        return $this->render('export/export_form.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    // ============================================================
    // WORKFLOW
    // ============================================================

    #[Route('/workflow/create', name: 'workflow_create', methods: ['POST'])]
    public function createWorkflow(Request $request): Response
    {
        $this->addFlash('success', 'Workflow créé avec succès !');
        return $this->redirectToRoute('app_dashboard');
    }

    // ============================================================
    // GESTION UTILISATEURS (ADMIN)
    // ============================================================

    #[Route('/admin/dashboard', name: 'admin_dashboard')]
    public function adminDashboard(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/admin/users', name: 'admin_users')]
    public function adminUsers(UserRepository $userRepository): Response
    {
        return $this->render('admin/users.html.twig', [
            'user' => $this->getUser(),
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/admin/users/new', name: 'admin_user_new')]
    public function adminUserNew(
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
            'user' => $this->getUser(),
            'form' => $form->createView(),
            'title' => 'Ajouter un utilisateur',
            'isNew' => true,
        ]);
    }

    #[Route('/admin/users/{id}/edit', name: 'admin_user_edit')]
    public function adminUserEdit(
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
            'user' => $this->getUser(),
            'form' => $form->createView(),
            'title' => 'Modifier ' . ($targetUser->getFullName() ?? $targetUser->getEmail()),
            'isEdit' => true,
            'targetUser' => $targetUser,
        ]);
    }

    #[Route('/admin/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function adminUserDelete(
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
            $this->addFlash('error', '❌ Token de sécurité invalide.');
            return $this->redirectToRoute('admin_users');
        }

        try {
            $userName = $targetUser->getFullName() ?: $targetUser->getEmail();
            $em->remove($targetUser);
            $em->flush();
            $this->addFlash('success', "✅ Utilisateur '{$userName}' supprimé !");
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ Erreur lors de la suppression');
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/admin/users/{id}/toggle', name: 'admin_user_toggle')]
    public function adminUserToggle(User $targetUser, EntityManagerInterface $em): Response
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

    #[Route('/admin/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function adminUserResetPassword(
        User $targetUser,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reset' . $targetUser->getId(), $token)) {
            $this->addFlash('error', '❌ Token de sécurité invalide.');
            return $this->redirectToRoute('admin_users');
        }

        $newPassword = $request->request->get('new_password');
        
        if (!$newPassword || strlen($newPassword) < 6) {
            $this->addFlash('error', '❌ Le mot de passe doit contenir au moins 6 caractères !');
            return $this->redirectToRoute('admin_users');
        }

        $targetUser->setPassword($hasher->hashPassword($targetUser, $newPassword));
        $em->flush();
        
        $this->addFlash('success', "✅ Mot de passe réinitialisé avec succès !");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/admin/settings', name: 'admin_settings')]
    public function adminSettings(Request $request): Response
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

    // ============================================================
    // DÉCONNEXION
    // ============================================================

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Intercepted by the firewall.');
    }
}