<?php
// src/Service/TicketHistoryService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class TicketHistoryService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function add(Ticket $ticket, User $user, string $action, array|string|null $data = null): TicketHistory
    {
        $details = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
        $metadata = is_array($data) ? $data : null;

        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);
        $history->setMetadata($metadata);

        $this->em->persist($history);
        $this->em->flush();

        return $history;
    }

    public function getHistory(Ticket $ticket): array
    {
        return $this->em->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);
    }
}