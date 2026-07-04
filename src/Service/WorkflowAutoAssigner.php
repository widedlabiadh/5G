<?php

namespace App\Service;

use App\Entity\AnalyseResultat;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class WorkflowAutoAssigner
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em
    ) {
    }

    /**
     * Assigns one engineering user per site-type group and creates a task for each.
     * FO sites  → ingenierie_ip
     * FH sites  → ingenierie_fh
     * BH/Backbone → ingenierie_backbone
     *
     * @param array<int, AnalyseResultat|array<string, mixed>> $sites
     * @return User[]
     */
    public function assignUsersForSites(array $sites, Ticket $ticket): array
    {
        $groups = $this->groupSitesByRole($sites);
        $assignedUsers = [];
        $stepOrder = 1;

        foreach ($groups as $role => $roleSites) {
            $user = $this->findFirstActiveUserByRole($role);
            if (!$user || isset($assignedUsers[$user->getId()])) {
                continue;
            }

            $this->createTask(
                $ticket,
                $user,
                $this->titleForRole($role),
                $this->stepCodeForRole($role),
                $stepOrder++,
                count($roleSites)
            );
            $assignedUsers[$user->getId()] = $user;
        }

        if (empty($assignedUsers)) {
            $fallbackUser = $this->findFallbackUser();
            if ($fallbackUser) {
                $this->createTask($ticket, $fallbackUser, 'Traitement workflow', 'workflow_step', $stepOrder, count($sites));
                $assignedUsers[$fallbackUser->getId()] = $fallbackUser;
            }
        }

        return array_values($assignedUsers);
    }

    /**
     * Groups sites by the engineering role responsible for their type.
     *
     * @param array<int, AnalyseResultat|array<string, mixed>> $sites
     * @return array<string, list<AnalyseResultat|array<string, mixed>>>
     */
    private function groupSitesByRole(array $sites): array
    {
        $groups = [];
        foreach ($sites as $site) {
            $type = strtoupper(trim((string) $this->siteValue($site, 'typeTrans')));
            $role = $this->roleForType($type);
            $groups[$role][] = $site;
        }
        return $groups;
    }

    /**
     * Maps a site type_trans to the responsible engineering role.
     */
    private function roleForType(string $type): string
    {
        if (str_starts_with($type, 'FH') || str_starts_with($type, 'RADIO')) {
            return 'ingenierie_fh';
        }

        if (str_starts_with($type, 'BH') || $type === 'BACKBONE') {
            return 'ingenierie_backbone';
        }

        // FO and everything else (FO, FO_OPT, FIBRE, unknown) → ingenierie_ip
        return 'ingenierie_ip';
    }

    /**
     * @param AnalyseResultat|array<string, mixed> $site
     */
    private function siteValue(AnalyseResultat|array $site, string $field): mixed
    {
        if (is_array($site)) {
            return $site[$field] ?? null;
        }

        return match ($field) {
            'typeTrans'      => $site->getTypeTrans(),
            'classification' => $site->getClassification(),
            default          => null,
        };
    }

    private function findFirstActiveUserByRole(string $role): ?User
    {
        return $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->andWhere('u.role = :role')
            ->setParameter('active', true)
            ->setParameter('role', $role)
            ->orderBy('u.fullName', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findFallbackUser(): ?User
    {
        return $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->andWhere('u.role NOT IN (:excludedRoles)')
            ->setParameter('active', true)
            ->setParameter('excludedRoles', ['admin', 'superuser'])
            ->orderBy('u.fullName', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function createTask(Ticket $ticket, User $user, string $title, string $stepCode, int $stepOrder, int $siteCount): void
    {
        $task = new TicketTask();
        $task->setTicket($ticket);
        $task->setAssignedTo($user);
        $task->setTitle($title);
        $task->setDescription(sprintf('Traitement automatique du workflow "%s" pour %d site(s).', $ticket->getTitle(), $siteCount));
        $task->setStatus(TicketTask::STATUS_PENDING);
        $task->setStepCode($stepCode);
        $task->setStepOrder($stepOrder);

        $this->em->persist($task);
    }

    private function titleForRole(string $role): string
    {
        return match ($role) {
            'ingenierie_ip'       => 'Analyse ingénierie IP/FO',
            'ingenierie_fh'       => 'Analyse ingénierie FH',
            'ingenierie_backbone' => 'Analyse ingénierie Backhaul Sharing',
            'support_fo'          => 'Analyse et traitement FO',
            'support_fh'          => 'Analyse support FH',
            'support_radio'       => 'Analyse support Radio',
            'deploiement'         => 'Planification déploiement',
            default               => 'Traitement workflow',
        };
    }

    private function stepCodeForRole(string $role): string
    {
        return match ($role) {
            'ingenierie_ip'       => 'ip_analysis',
            'ingenierie_fh'       => 'engineering_radio',
            'ingenierie_backbone' => 'backbone_analysis',
            'support_fo'          => 'support_fo',
            'support_fh'          => 'support_fh',
            'support_radio'       => 'support_radio',
            'deploiement'         => 'deployment',
            default               => 'workflow_step',
        };
    }
}
