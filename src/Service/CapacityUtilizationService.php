<?php

namespace App\Service;

/**
 * Capacité effective pour le taux d'utilisation selon la classification :
 * - TF, ONLY_FDD, FDD → capacité FDD uniquement
 * - Autres → somme TDD + FDD
 */
class CapacityUtilizationService
{
    public function usesFddCapacityOnly(string $classification): bool
    {
        $classification = strtoupper(trim($classification));

        return in_array($classification, ['TF', 'ONLY_FDD', 'FDD', '-'], true);
    }

    public function resolveEffectiveCapacityMbps(
        string $classification,
        float $capaciteTddMbps,
        float $capaciteFddMbps,
        ?float $fallbackCapaciteMbps = null,
    ): float {
        $capaciteFddMbps = max(0.0, $capaciteFddMbps);
        $fallbackCapaciteMbps = max(0.0, (float) ($fallbackCapaciteMbps ?? 0.0));

        if ($capaciteFddMbps > 0) {
            return $capaciteFddMbps;
        }

        return $fallbackCapaciteMbps;
    }

    public function computeRate(?float $trafficMbps, ?float $capacityMbps): ?float
    {
        $trafficMbps = max(0.0, (float) ($trafficMbps ?? 0.0));
        $capacityMbps = max(0.0, (float) ($capacityMbps ?? 0.0));

        if ($trafficMbps <= 0 || $capacityMbps <= 0) {
            return null;
        }

        return round(($trafficMbps / $capacityMbps) * 100, 2);
    }

    public function computeUtilizationRate(
        float $maxTraficMbps,
        string $classification,
        float $capaciteTddMbps,
        float $capaciteFddMbps,
        ?float $fallbackCapaciteMbps = null,
    ): ?float {
        return $this->computeRate(
            $maxTraficMbps,
            $this->resolveEffectiveCapacityMbps(
                $classification,
                $capaciteTddMbps,
                $capaciteFddMbps,
                $fallbackCapaciteMbps
            )
        );
    }

    /**
     * @return array{taux_utilisation: ?float, taux_utilisation_tdd: ?float, taux_utilisation_fdd: ?float}
     */
    public function computeUtilizationRates(
        float $maxTraficMbps,
        float $maxTddMbps,
        float $maxFddMbps,
        string $classification,
        float $capaciteTddMbps,
        float $capaciteFddMbps,
        ?float $fallbackCapaciteMbps = null,
    ): array {
        $cls = strtoupper(preg_replace('/\s+/u', '', $classification));

        // COTRANS/NO_COTRANS : taux global vide — seuls taux TDD et FDD sont significatifs
        $tauxGlobal = str_contains($cls, 'COTRANS')
            ? null
            : $this->computeUtilizationRate(
                $maxTraficMbps,
                $classification,
                $capaciteTddMbps,
                $capaciteFddMbps,
                $fallbackCapaciteMbps
            );

        return [
            'taux_utilisation'     => $tauxGlobal,
            'taux_utilisation_tdd' => $this->computeRate($maxTddMbps, $capaciteTddMbps),
            'taux_utilisation_fdd' => $this->computeRate($maxFddMbps, $capaciteFddMbps),
        ];
    }
}
