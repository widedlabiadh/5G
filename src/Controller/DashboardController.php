<?php
// src/Controller/DashboardController.php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\TicketRepository;
use App\Service\Rag\NetwonRagAgent;
use App\Service\StatisticsService;
use App\Service\GeocodingService;
use App\Service\AlertService;
use App\Service\Ia\AnomalyDetectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    private StatisticsService $statsService;
    private GeocodingService $geocodingService;
    private AlertService $alertService;
    private AnomalyDetectionService $anomalyDetectionService;

    public function __construct(
        StatisticsService $statsService, 
        GeocodingService $geocodingService, 
        AlertService $alertService,
        AnomalyDetectionService $anomalyDetectionService
    ) {
        $this->statsService = $statsService;
        $this->geocodingService = $geocodingService;
        $this->alertService = $alertService;
        $this->anomalyDetectionService = $anomalyDetectionService;
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em, UserRepository $userRepository, TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();
        $role = $user->getRole();

        $stats = $this->statsService->getGlobalStats();
        $sitesCritiques = $this->statsService->getSitesCritiques(20);
        $totalSitesCritiques = $this->statsService->getTotalSitesCritiquesCount();
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
                // CORRECTION: Ne pas appeler l'API IA ici, juste vérifier la disponibilité
                $specificData['ia_anomalies'] = [];
                $specificData['ia_available'] = $this->anomalyDetectionService->isAvailable();
                break;
            case 'controleur':
                $tickets = $em->getConnection()->fetchAllAssociative(
                    'SELECT t.*, u.full_name as created_by_name FROM ticket t LEFT JOIN "user" u ON t.created_by_id = u.id ORDER BY t.created_at DESC LIMIT 20'
                );
                $ticketStats = $ticketRepository->getStatistics();
                $specificData = [
                    'tickets' => $tickets,
                    'ticketStats' => $ticketStats,
                    'ticketsActifs' => $ticketStats['open'] + $ticketStats['in_progress'] + $ticketStats['pending'],
                    'ticketsOverdue' => $ticketStats['overdue'],
                    'siteHealthStats' => $this->statsService->getSiteHealthStats(),
                    'siteHealthByGovernorate' => $this->statsService->getSiteHealthByGovernorate(),
                    'statsByGouvernorat' => $this->statsService->getSiteHealthByGovernorate(),
                    'topHealthRisks' => $this->statsService->getTopSiteHealthRisks(8),
                ];
                break;
            case 'support_fo':
            case 'support_fh':
            case 'support_radio':
            case 'ingenierie_fh':
            case 'ingenierie_ip':
            case 'ingenierie_backbone':
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
            'sites_critiques' => $sitesCritiques,
            'total_sites_critiques' => $totalSitesCritiques,
            'sites_gps' => $sitesGps,
            'derniere_analyse' => $derniereAnalyse,
            'alerts' => $this->alertService->getActiveAlerts(),
        ], $specificData));
    }

    #[Route('/dashboard/chatbot', name: 'superuser_chatbot', methods: ['GET'])]
    public function chatbot(): Response
    {
        return $this->redirectToRoute('assistant');
    }

    #[Route('/dashboard/chatbot/ask', name: 'superuser_chatbot_ask', methods: ['POST'])]
    public function askChatbot(Request $request, NetwonRagAgent $ragAgent): JsonResponse
    {
        return $this->forward(AssistantController::class . '::ask', [
            'request' => $request,
            'ragAgent' => $ragAgent,
        ]);
    }

    #[Route('/ia/anomalies', name: 'ia_anomalies', methods: ['GET'])]
    public function getIaAnomalies(Request $request): JsonResponse
    {
        $site = $request->query->get('site');
        $limit = (int) $request->query->get('limit', 10);
        
        try {
            if ($site) {
                $result = $this->anomalyDetectionService->detectAnomaliesForSite($site);
            } else {
                $result = $this->anomalyDetectionService->detectAnomaliesForAllSites(false);
            }
            
            // Si on a un résultat avec anomalies, limiter le nombre
            if (isset($result['anomalies']) && is_array($result['anomalies']) && $limit > 0) {
                $result['anomalies'] = array_slice($result['anomalies'], 0, $limit);
                $result['total'] = count($result['anomalies']);
            }
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'anomalies' => []
            ], 500);
        }
    }

    #[Route('/ia/anomalies/run', name: 'ia_anomalies_run', methods: ['POST'])]
    public function runIaAnomalies(): JsonResponse
    {
        try {
            $result = $this->anomalyDetectionService->runDailyAnalysis();
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/ia/anomalies/stats', name: 'ia_anomalies_stats', methods: ['GET'])]
    public function getIaAnomaliesStats(Request $request): JsonResponse
    {
        $jours = (int) $request->query->get('jours', 30);
        
        try {
            $result = $this->anomalyDetectionService->getAnomalyStats($jours);
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/ia/anomalies/recentes', name: 'ia_anomalies_recentes', methods: ['GET'])]
    public function getIaAnomaliesRecentes(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        
        try {
            $anomalies = $conn->fetchAllAssociative("
                SELECT 
                    site, 
                    etat as type_anomalie, 
                    trafic_j, 
                    capacite_mbps, 
                    taux_utilisation,
                    message, 
                    date_alerte,
                    CASE 
                        WHEN etat = 'PRE_CONGESTION' THEN 'URGENTE'
                        WHEN etat IN ('PIC_SOUDAN', 'CHUTE_BRUTALE') THEN 'HAUTE'
                        ELSE 'MOYENNE'
                    END as priorite
                FROM site_alert
                WHERE type_trans = 'IA_ANOMALY'
                  AND date_alerte::date = CURRENT_DATE
                  AND alerte_lue = false
                ORDER BY date_alerte DESC
                LIMIT 10
            ");
            
            return $this->json([
                'status' => 'success',
                'total' => count($anomalies),
                'anomalies' => $anomalies
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/ia/status', name: 'ia_status', methods: ['GET'])]
    public function iaStatus(): JsonResponse
    {
        return $this->json([
            'available' => $this->anomalyDetectionService->isAvailable(),
            'api_url' => 'http://localhost:8000'
        ]);
    }
}