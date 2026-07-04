<?php

namespace App\Controller;

use App\Entity\TicketNotification;
use App\Entity\User;
use App\Repository\TicketNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workflow/notifications')]
class WorkflowNotificationController extends AbstractController
{
    #[Route('', name: 'workflow_notifications', methods: ['GET'])]
    public function index(TicketNotificationRepository $notificationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('workflow/notifications/index.html.twig', [
            'notifications' => $notificationRepository->findLatestForUser($user, 50),
        ]);
    }

    #[Route('/{id}/read', name: 'workflow_notification_read', methods: ['POST'])]
    public function markRead(TicketNotification $notification, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        if ($notification->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $notification->setIsRead(true);
        $em->flush();

        $redirect = $request->headers->get('referer');
        if (is_string($redirect) && $redirect !== '') {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('workflow_notifications');
    }
}
