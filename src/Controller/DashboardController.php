<?php
// src/Controller/DashboardController.php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\StatisticsService;
use App\Service\GeocodingService;
use App\Service\AlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    private StatisticsService $statsService;
    private GeocodingService $geocodingService;
    private AlertService $alertService;

    public function __construct(StatisticsService $statsService, GeocodingService $geocodingService, AlertService $alertService)
    {
        $this->statsService    = $statsService;
        $this->geocodingService = $geocodingService;
        $this->alertService    = $alertService;
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        $role = $user->getRole();

        $stats = $this->statsService->getGlobalStats();
        $sitesCritiques = $this->statsService->getSitesCritiques(20);
        $totalSitesCritiques = $this->statsService->getTotalSitesCritiquesCount(); // ← NOUVEAU: total réel des sites critiques
        $sitesGps = $this->statsService->getSitesForGps($this->geocodingService);
        $derniereAnalyse = null;

        $specificData = [];

        switch ($role) {
            case 'admin':
                $users = $userRepository->findAll();
                $specificData = [
                    'users' => $users,
                    'totalUsers' => count($users),
                    'activeUsers' => count(array_filter($users, fn($u) => $u->isActive())),
                    'inactiveUsers' => count(array_filter($users, fn($u) => !$u->isActive())),
                    'roleCount' => count(array_unique(array_map(fn($u) => $u->getRole(), $users))),
                ];
                break;
            case 'superuser':
                // rien de spécifique
                break;
            case 'controleur':
                $tickets = $em->getConnection()->fetchAllAssociative(
                    'SELECT t.*, u.full_name as created_by_name FROM ticket t LEFT JOIN "user" u ON t.created_by_id = u.id ORDER BY t.created_at DESC LIMIT 20'
                );
                $specificData = [
                    'tickets' => $tickets,
                    'ticketsActifs' => count($tickets),
                    'ticketsOverdue' => 0,
                ];
                break;
            case 'support_fo':
            case 'support_fh':
            case 'ingenierie_fh':
            case 'ingenierie_ip':
                $taskRepository = $em->getRepository(\App\Entity\TicketTask::class);
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
                    'myPendingTasks' => $myPendingTasks,
                    'myInProgressTasks' => $myInProgressTasks,
                    'myCompletedTasks' => $myCompletedTasks,
                    'myOverdueTasks' => $myOverdueTasks,
                ];
                break;
            default:
                $specificData = [];
        }

        return $this->render('dashboard/index.html.twig', array_merge([
            'user' => $user,
            'role' => $role,
            'roleLabel' => $user->getRoleLabel(),
            'stats' => $stats,
            'sites_critiques' => $sitesCritiques,           // Pour l'affichage (20 premiers)
            'total_sites_critiques' => $totalSitesCritiques, // ← NOUVEAU: pour le compteur
            'sites_gps' => $sitesGps,
            'derniere_analyse'      => $derniereAnalyse,
            'alerts'                => $this->alertService->getActiveAlerts(),
        ], $specificData));
    }
}