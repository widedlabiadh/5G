<?php
// src/Twig/WorkflowExtension.php

namespace App\Twig;

use App\Entity\Ticket;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class WorkflowExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('status_badge', [$this, 'getStatusBadge'], ['is_safe' => ['html']]),
            new TwigFilter('status_color', [$this, 'getStatusColor']),
            new TwigFilter('action_type_icon', [$this, 'getActionTypeIcon'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_transition', [$this, 'canTransition']),
        ];
    }

    public function getStatusBadge(string $status): string
    {
        $colors = [
            'open' => '#f97316',
            'in_progress' => '#3b82f6',
            'pending_validation' => '#8b5cf6',
            'closed' => '#10b981',
            'cancelled' => '#ef4444',
        ];
        
        $labels = [
            'open' => '📋 Ouvert',
            'in_progress' => '⚙️ En cours',
            'pending_validation' => '✅ En validation',
            'closed' => '✔️ Clôturé',
            'cancelled' => '❌ Annulé',
        ];
        
        $color = $colors[$status] ?? '#64748b';
        $label = $labels[$status] ?? $status;
        
        return sprintf('<span class="badge" style="background: %s; color: white;">%s</span>', $color, $label);
    }

    public function getStatusColor(string $status): string
    {
        return match($status) {
            'open' => '#f97316',
            'in_progress' => '#3b82f6',
            'pending_validation' => '#8b5cf6',
            'closed' => '#10b981',
            'cancelled' => '#ef4444',
            default => '#64748b',
        };
    }

    public function getActionTypeIcon(string $actionType): string
    {
        return match($actionType) {
            'swap_10g' => '<i class="fas fa-exchange-alt"></i>',
            'tf_balancing' => '<i class="fas fa-balance-scale"></i>',
            'upgrade_capacite' => '<i class="fas fa-arrow-up"></i>',
            'intervention_urgence' => '<i class="fas fa-exclamation-triangle"></i>',
            default => '<i class="fas fa-ticket-alt"></i>',
        };
    }

    public function canTransition(Ticket $ticket, string $transition, ?string $role = null): bool
    {
        // Seuls superuser et admin peuvent valider
        if (in_array($transition, ['validate', 'approve', 'reject', 'cancel'])) {
            return $role === 'superuser' || $role === 'admin';
        }
        
        // start possible par tous
        if ($transition === 'start') {
            return $ticket->getStatus() === 'open';
        }
        
        return false;
    }
}