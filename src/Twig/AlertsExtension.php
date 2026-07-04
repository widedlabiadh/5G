<?php
// src/Twig/AlertsExtension.php

namespace App\Twig;

use App\Service\AlertService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class AlertsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private AlertService $alertService) {}

    public function getGlobals(): array
    {
        $alerts = $this->alertService->getActiveAlerts();
        return [
            'alerts' => $alerts,
            'total_alertes' => count($alerts),
            'total_toutes_alertes' => $this->alertService->countToutesAlertes(),
            'total_alertes_non_lues' => $this->alertService->countAlertesNonLues(),
        ];
    }
}
