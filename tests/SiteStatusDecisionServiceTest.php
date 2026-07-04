<?php

namespace App\Tests;

use App\Service\SiteStatusDecisionService;
use PHPUnit\Framework\TestCase;

class SiteStatusDecisionServiceTest extends TestCase
{
    public function testEtatFollowsUtilizationAndOccurrencesMatrix(): void
    {
        $service = new SiteStatusDecisionService();

        self::assertSame('RISQUE_DE_CONGESTION', $service->decideEtat([
            'type_trans' => 'BH',
            'taux_utilisation' => 90,
            'nombre_occurrences' => 0,
        ]));

        self::assertSame('CONGESTION', $service->decideEtat([
            'type_trans' => 'BH',
            'taux_utilisation' => 95,
            'nombre_occurrences' => 57,
        ]));

        self::assertSame('BRIDAGE', $service->decideEtat([
            'type_trans' => 'BH',
            'taux_utilisation' => 89.99,
            'nombre_occurrences' => 50,
        ]));

        self::assertSame('OK', $service->decideEtat([
            'type_trans' => 'BH',
            'taux_utilisation' => 89.99,
            'nombre_occurrences' => 49,
        ]));

        self::assertSame('RISQUE_DE_CONGESTION', $service->decideEtat([
            'type_trans' => 'FH',
            'taux_utilisation' => 105.01,
            'nombre_occurrences' => 20,
        ]));

        self::assertSame('A_VERIFIER_CAPACITE', $service->decideEtat([
            'type_trans' => 'NON_DEFINI',
            'taux_utilisation' => 105.01,
            'nombre_occurrences' => 20,
        ]));

        self::assertSame([
            'statut' => 'SOUS_OBSERVATION',
            'label' => 'SOUS OBSERVATION',
            'etat' => 'RISQUE_DE_CONGESTION',
        ], $service->decide([
            'type_trans' => 'BH',
            'taux_utilisation' => 90,
            'nombre_occurrences' => 56,
        ]));
    }
}
