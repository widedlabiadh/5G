<?php

namespace App\Controller;

use App\Entity\TicketComment;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Form\WorkflowDecisionType;
use App\Repository\TicketTaskRepository;
use App\Service\WorkflowEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workflow/team')]
class TeamWorkflowController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'team_workflow_index', methods: ['GET'])]
    public function index(TicketTaskRepository $taskRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        $allTasks = $taskRepository->findByAssignedUser($user);
        $activeTasks = array_values(array_filter(
            $allTasks,
            fn (TicketTask $task): bool => $task->getStatus() !== TicketTask::STATUS_DONE
        ));
        $doneTasks = array_values(array_filter(
            $allTasks,
            fn (TicketTask $task): bool => $task->getStatus() === TicketTask::STATUS_DONE
        ));

        return $this->render('workflow/team/index.html.twig', [
            'tasks' => $activeTasks,
            'doneTasks' => $doneTasks,
            'serviceName' => $user->getRoleLabel(),
        ]);
    }

    #[Route('/task/{id}', name: 'team_workflow_task_show', methods: ['GET', 'POST'])]
    public function task(
        TicketTask $task,
        Request $request,
        WorkflowEngineService $engine
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas traiter cette étape.');
        }

        if ($task->getStatus() === TicketTask::STATUS_PENDING) {
            $engine->startTask($task, $user);
        }

        $ticket = $task->getTicket();
        $sites = [];
        if ($ticket && !$ticket->getTicketSites()->isEmpty()) {
            foreach ($ticket->getTicketSites() as $ticketSite) {
                $sites[] = $ticketSite->getSiteName();
            }
        } elseif ($ticket?->getSite()) {
            $sites[] = (string) $ticket->getSite()->getSite();
        }

        $form = $this->createForm(WorkflowDecisionType::class, null, [
            'choices' => $engine->choicesFor($task),
            'sites' => $sites,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $uploadedFile = $form->get('proofFile')->getData();
            $filename = null;

            if ($uploadedFile) {
                $filename = uniqid('workflow_proof_', true) . '.' . $uploadedFile->guessExtension();
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/ticket_proofs';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                try {
                    $uploadedFile->move($uploadDir, $filename);
                } catch (FileException) {
                    $this->addFlash('error', 'Erreur lors de l’upload du fichier.');
                    return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
                }
            }

            $siteValidated = $form->has('siteValidated') ? $form->get('siteValidated')->getData() : null;

            $engine->completeTaskWithSiteValidation(
                $task,
                $user,
                $data['decision'],
                $siteValidated,
                $data['comment'] ?? null,
                $filename
            );

            $this->addFlash('success', 'Tâche traitée avec succès.');
            return $this->redirectToRoute('team_workflow_index');
        }

        return $this->render('workflow/team/task.html.twig', [
            'task' => $task,
            'ticket' => $ticket,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/task/{id}/comment', name: 'team_workflow_add_comment', methods: ['POST'])]
    public function addComment(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $message = trim((string) $request->request->get('comment_message'));
        if ($message === '') {
            $this->addFlash('error', 'Le commentaire ne peut pas être vide.');
            return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
        }

        $comment = new TicketComment();
        $comment->setTicket($task->getTicket());
        $comment->setUser($user);
        $comment->setMessage($message);

        $this->em->persist($comment);
        $this->em->flush();

        $this->addFlash('success', 'Commentaire ajouté.');
        return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
    }
}
