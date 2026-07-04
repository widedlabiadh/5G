<?php
// src/Controller/WorkflowController.php

namespace App\Controller;

use App\DTO\TicketSummaryDTO;
use App\Entity\AnalyseResultat;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Form\TicketType;
use App\Repository\AnalyseResultatRepository;
use App\Repository\TicketRepository;
use App\Repository\UmptSiteRepository;
use App\Service\NotificationService;
use App\Service\TicketHistoryService;
use App\Service\TicketWorkflowService;
use App\Service\UmptCompatibilityService;
use App\Service\WorkflowAutoAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workflow')]
class WorkflowController extends AbstractController
{
    #[Route('/', name: 'workflow_index')]
    public function index(TicketRepository $ticketRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $role = $user->getRole();
        
        if ($role === 'superuser' || $role === 'admin') {
            $tickets = $ticketRepository->findAllOptimized();
            $stats = $ticketRepository->getStatistics();
            $kanban = $ticketRepository->getKanbanCards();
        } else {
            return $this->redirectToRoute('team_workflow_index');
        }
        
        $ticketsDTO = array_map(fn($t) => TicketSummaryDTO::fromEntity($t, $role), $tickets);
        
        return $this->render('workflow/index.html.twig', [
            'user' => $user,
            'tickets' => $ticketsDTO,
            'stats' => $stats,
            'kanban' => $kanban,
            'role' => $role,
        ]);
    }

    #[Route('/new', name: 'workflow_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        AnalyseResultatRepository $siteRepository,
        WorkflowAutoAssigner $autoAssigner,
        NotificationService $notificationService,
        UmptSiteRepository $umptRepository,
        UmptCompatibilityService $umptCompatibility
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $ticket = new Ticket();
        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedSiteIds = array_values(array_unique(array_filter(
                array_map('intval', $request->request->all('site_ids')),
                static fn (int $siteId): bool => $siteId > 0
            )));

            if (empty($selectedSiteIds)) {
                $this->addFlash('error', 'Veuillez choisir au moins un site.');
                return $this->redirectToRoute('workflow_new');
            }

            $ticket->setCreatedBy($this->getUser());
            $ticket->setStatus('open');
            $ticket->setProgress(0);
            $em->persist($ticket);

            $selectedSites = $siteRepository->findWorkflowSitesByIds($selectedSiteIds);
            if (empty($selectedSites)) {
                $this->addFlash('error', 'Aucun site valide sélectionné.');
                return $this->redirectToRoute('workflow_new');
            }
            $umptMap = $umptRepository->findMapBySites(array_column($selectedSites, 'site'));

            foreach ($selectedSites as $site) {
                $siteName = (string) $site['site'];
                $umpt = $umptMap[UmptSiteRepository::normalizeSite($siteName)] ?? null;

                if ($ticket->getSite() === null) {
                    $ticket->setSite($em->getReference(AnalyseResultat::class, (int) $site['id']));
                }

                $ticketSite = new TicketSite();
                $ticketSite->setTicket($ticket);
                $ticketSite->setSourceSiteId((int) $site['id']);
                $ticketSite->setSiteName($siteName);
                $ticketSite->setTypeTrans($site['typeTrans'] ?? null);
                $ticketSite->setServiceName($this->serviceNameForSite($site));
                $ticketSite->setMaxTrafic(isset($site['maxTrafic']) ? (float) $site['maxTrafic'] : null);
                $ticketSite->setUmptType($umpt?->getCardType());
                $ticketSite->setSwapRequired($umpt?->isSwapRequired());
                $ticketSite->setSwapComment($umpt ? $umpt->getSwapComment() : $umptCompatibility->commentFor(null));
                $em->persist($ticketSite);
            }

            $assignedUsers = $autoAssigner->assignUsersForSites($selectedSites, $ticket);
            $em->flush();

            $workflowService->refreshProgress($ticket);
            $notificationService->notifyWorkflowAssignment($ticket, $assignedUsers, count($assignedUsers));

            $this->addFlash('success', sprintf('Workflow créé avec succès (%d site(s), %d utilisateur(s) assigné(s)).', count($selectedSites), count($assignedUsers)));
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }

        return $this->render('workflow/new.html.twig', [
            'user' => $this->getUser(),
            'form' => $form->createView(),
            'availableSites' => $siteRepository->findWorkflowChoices(),
        ]);
        
        if ($request->isMethod('POST')) {
            $ticket = new Ticket();
            $ticket->setTitle($request->request->get('title'));
            $ticket->setDescription($request->request->get('description'));
            $ticket->setActionType($request->request->get('actionType'));
            $ticket->setCreatedBy($this->getUser());
            
            if ($request->request->get('deadlineAt')) {
                $ticket->setDeadlineAt(new \DateTime($request->request->get('deadlineAt')));
            }
            
            if ($request->request->get('site')) {
                $site = $siteRepository->find($request->request->get('site'));
                $ticket->setSite($site);
            }
            
            $em->persist($ticket);
            
            $assignedUsers = $request->request->all('assignedUsers') ?? [];
            foreach ($assignedUsers as $userId) {
                $assignedUser = $userRepository->find($userId);
                if ($assignedUser) {
                    $task = new TicketTask();
                    $task->setTicket($ticket);
                    $task->setAssignedTo($assignedUser);
                    $task->setTitle('Traitement: ' . $ticket->getTitle());
                    $task->setDescription($ticket->getDescription());
                    $task->setStepCode('manual_workflow');
                    $task->setStepOrder(1);
                    $em->persist($task);
                }
            }
            
            $em->flush();
            
            $workflowService->refreshProgress($ticket);
            
            $this->addFlash('success', '✅ Ticket créé avec succès !');
            return $this->redirectToRoute('workflow_index');
        }
        
        // Récupérer tous les utilisateurs actifs SAUF admin et superuser
        $users = $userRepository->createQueryBuilder('u')
            ->where('u.isActive = :active')
            ->andWhere('u.role NOT IN (:excludedRoles)')
            ->setParameter('active', true)
            ->setParameter('excludedRoles', ['admin', 'superuser'])
            ->orderBy('u.role', 'ASC')
            ->addOrderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
        
        return $this->render('workflow/new.html.twig', [
            'user' => $this->getUser(),
            'sites' => $siteRepository->findAll(),
            'users' => $users,
        ]);
    }

    private function serviceNameForSite($site): string
    {
        $type = strtoupper((string) (is_array($site) ? ($site['typeTrans'] ?? '') : $site->getTypeTrans()));

        if (str_contains($type, 'FO') || str_contains($type, 'FIBRE')) {
            return 'FO';
        }

        if (str_contains($type, 'FH') || str_contains($type, 'RADIO')) {
            return 'Radio/FH';
        }

        if (str_contains($type, 'BH') || str_contains($type, 'BACKBONE')) {
            return 'Backhaul Sharing/IP';
        }

        return 'IP';
    }

    #[Route('/show/{id}', name: 'workflow_show')]
    public function show(Ticket $ticket): Response
    {
        return $this->render('workflow/show.html.twig', [
            'user' => $this->getUser(),
            'ticket' => $ticket,
            'history' => $ticket->getHistory(),
        ]);
    }

    // ============================================================
    // GESTION DES TÂCHES
    // ============================================================

    #[Route('/task/{id}/complete', name: 'workflow_task_complete', methods: ['GET', 'POST'])]
    public function completeTask(
        TicketTask $task,
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        TicketHistoryService $historyService
    ): Response {
        $user = $this->getUser();
        
        // Vérifier que l'utilisateur est bien assigné à cette tâche
        if ($task->getAssignedTo()->getId() !== $user->getId()) {
            $this->addFlash('error', '❌ Vous n\'êtes pas autorisé à compléter cette tâche.');
            return $this->redirectToRoute('workflow_show', ['id' => $task->getTicket()->getId()]);
        }

        if ($request->isMethod('GET')) {
            return $this->render('workflow/task_complete.html.twig', [
                'user' => $user,
                'task' => $task,
            ]);
        }
        
        $comment = $request->request->get('comment');
        
        $task->setStatus('done');
        $task->setCompletedAt(new \DateTime());
        $task->setComment($comment);
        
        // Gestion du fichier preuve
        $file = $request->files->get('proofFile');
        if ($file) {
            $filename = uniqid() . '.' . $file->guessExtension();
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/ticket_proofs';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $file->move($uploadDir, $filename);
            $task->setProofFile($filename);
        }
        
        $em->flush();
        
        // Mettre à jour la progression du ticket
        $workflowService->refreshProgress($task->getTicket());
        
        // Ajouter à l'historique
        $historyService->add(
            $task->getTicket(),
            $user,
            'task_completed',
            'Tâche "' . $task->getTitle() . '" complétée'
        );
        
        $em->flush();
        
        $this->addFlash('success', '✅ Tâche marquée comme terminée !');
        return $this->redirectToRoute('workflow_show', ['id' => $task->getTicket()->getId()]);
    }

    #[Route('/task/{id}/start', name: 'workflow_task_start', methods: ['POST'])]
    public function startTask(
        TicketTask $task,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        TicketHistoryService $historyService
    ): Response {
        $user = $this->getUser();
        
        if ($task->getAssignedTo()->getId() !== $user->getId()) {
            $this->addFlash('error', '❌ Vous n\'êtes pas autorisé à démarrer cette tâche.');
            return $this->redirectToRoute('workflow_show', ['id' => $task->getTicket()->getId()]);
        }
        
        if ($task->getStatus() !== 'pending') {
            $this->addFlash('error', '❌ Cette tâche ne peut pas être démarrée.');
            return $this->redirectToRoute('workflow_show', ['id' => $task->getTicket()->getId()]);
        }
        
        $task->setStatus('in_progress');
        $task->setStartedAt(new \DateTime());
        $em->flush();
        
        // Mettre à jour la progression du ticket
        $workflowService->refreshProgress($task->getTicket());
        
        // Ajouter à l'historique
        $historyService->add(
            $task->getTicket(),
            $user,
            'task_started',
            'Tâche "' . $task->getTitle() . '" démarrée'
        );
        
        $em->flush();
        
        $this->addFlash('success', '✅ Tâche démarrée !');
        return $this->redirectToRoute('workflow_show', ['id' => $task->getTicket()->getId()]);
    }

    // ============================================================
    // GESTION DES COMMENTAIRES
    // ============================================================

    #[Route('/{id}/comment', name: 'workflow_comment_add', methods: ['POST'])]
    public function addComment(
        Ticket $ticket,
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        TicketHistoryService $historyService
    ): Response {
        $user = $this->getUser();
        $message = $request->request->get('message');
        
        if (empty($message)) {
            $this->addFlash('error', '❌ Le message ne peut pas être vide.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($user);
        $comment->setMessage($message);
        
        // Gestion du fichier joint
        $file = $request->files->get('filePath');
        if ($file) {
            $filename = uniqid() . '.' . $file->guessExtension();
            $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/ticket_comments', $filename);
            $comment->setFilePath($filename);
        }
        
        $em->persist($comment);
        $em->flush();
        
        // Ajouter à l'historique
        $historyService->add(
            $ticket,
            $user,
            'comment_added',
            'Commentaire ajouté: ' . substr($message, 0, 100)
        );
        
        $em->flush();
        
        $this->addFlash('success', '✅ Commentaire ajouté !');
        return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
    }

    // ============================================================
    // CLÔTURE DU TICKET
    // ============================================================

    #[Route('/{id}/close', name: 'workflow_close', methods: ['POST'])]
    public function closeTicket(
        Ticket $ticket,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        TicketHistoryService $historyService
    ): Response {
        $user = $this->getUser();
        
        // Seuls superuser et admin peuvent clôturer
        if (!in_array($user->getRole(), ['superuser', 'admin'])) {
            $this->addFlash('error', '❌ Vous n\'êtes pas autorisé à clôturer ce ticket.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        if ($ticket->getProgress() < 100) {
            $this->addFlash('error', '❌ Le ticket ne peut pas être clôturé car toutes les tâches ne sont pas terminées.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        if ($ticket->getStatus() === 'closed') {
            $this->addFlash('error', '❌ Ce ticket est déjà clôturé.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        $ticket->setStatus('closed');
        $ticket->setClosedAt(new \DateTime());
        $em->flush();
        
        // Ajouter à l'historique
        $historyService->add(
            $ticket,
            $user,
            'ticket_closed',
            'Ticket clôturé par ' . ($user->getFullName() ?? $user->getEmail())
        );
        
        $em->flush();
        
        $this->addFlash('success', '✅ Ticket clôturé avec succès !');
        return $this->redirectToRoute('workflow_index');
    }

    // ============================================================
    // RÉOUVERTURE D'UN TICKET
    // ============================================================

    #[Route('/{id}/reopen', name: 'workflow_reopen', methods: ['POST'])]
    public function reopenTicket(
        Ticket $ticket,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        TicketHistoryService $historyService
    ): Response {
        $user = $this->getUser();
        
        if (!in_array($user->getRole(), ['superuser', 'admin'])) {
            $this->addFlash('error', '❌ Vous n\'êtes pas autorisé à rouvrir ce ticket.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        if ($ticket->getStatus() !== 'closed') {
            $this->addFlash('error', '❌ Seuls les tickets clôturés peuvent être rouverts.');
            return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
        }
        
        $ticket->setStatus('in_progress');
        $ticket->setClosedAt(null);
        $em->flush();
        
        // Ajouter à l'historique
        $historyService->add(
            $ticket,
            $user,
            'ticket_reopened',
            'Ticket rouvert par ' . ($user->getFullName() ?? $user->getEmail())
        );
        
        $em->flush();
        
        $this->addFlash('success', '✅ Ticket rouvert avec succès !');
        return $this->redirectToRoute('workflow_show', ['id' => $ticket->getId()]);
    }

    // ============================================================
    // MES TÂCHES (POUR LES UTILISATEURS NON SUPERUSER)
    // ============================================================

    #[Route('/my-tasks', name: 'workflow_my_tasks')]
    public function myTasks(
        TicketRepository $ticketRepository,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        
        // Récupérer les tickets où l'utilisateur a des tâches
        $tickets = $ticketRepository->findByUserOptimized($user);
        
        $pendingTasks = [];
        $inProgressTasks = [];
        $completedTasks = [];
        
        foreach ($tickets as $ticket) {
            foreach ($ticket->getTasks() as $task) {
                if ($task->getAssignedTo()->getId() === $user->getId()) {
                    if ($task->getStatus() === 'pending') {
                        $pendingTasks[] = $task;
                    } elseif ($task->getStatus() === 'in_progress') {
                        $inProgressTasks[] = $task;
                    } elseif ($task->getStatus() === 'done') {
                        $completedTasks[] = $task;
                    }
                }
            }
        }
        
        return $this->render('workflow/my_tasks.html.twig', [
            'user' => $user,
            'pendingTasks' => $pendingTasks,
            'inProgressTasks' => $inProgressTasks,
            'completedTasks' => $completedTasks,
        ]);
    }
}
