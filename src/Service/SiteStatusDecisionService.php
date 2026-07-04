<?php

namespace App\Service;

/**
 * Décision site (critique / sous observation) : uniquement selon les occurrences
 * (totales et/ou TDD/FDD), seuils dynamiques = base 50 / 20 pour 7 jours × (durée / 7).
 */
class SiteStatusDecisionService
{
    public const CRITICAL_OCCURRENCES_PER_WEEK = 50;
    public const WATCH_OCCURRENCES_PER_WEEK = 20;
    public const RISK_CONGESTION_OCCURRENCES_LIMIT = 57;
    public const BASE_DAYS = 7;
    public const CONGESTION_RATE_THRESHOLD = 90.0;
    public const CAPACITY_CHECK_RATE_THRESHOLD = 105.0;
    public const CAPACITY_CHECK_CODE = 'A_VERIFIER_CAPACITE';
    public const RISK_CONGESTION_CODE = 'RISQUE_DE_CONGESTION';
    public const CAPACITY_CHECK_LABEL = 'CAPACITE A VERIFIER';
    private const CAPACITE_10G_MBPS = 10000.0;

    public function getOccurrenceThreshold(int $dureeJours, int $baseThreshold, int $baseDays = self::BASE_DAYS): int
    {
        $dureeJours = max(1, $dureeJours);
        $baseDays = max(1, $baseDays);

        return max(1, (int) ceil(($baseThreshold * $dureeJours) / $baseDays));
    }

    public function getCriticalThreshold(int $dureeJours): int
    {
        return $this->getOccurrenceThreshold($dureeJours, self::CRITICAL_OCCURRENCES_PER_WEEK);
    }

    public function getWatchThreshold(int $dureeJours): int
    {
        return $this->getOccurrenceThreshold($dureeJours, self::WATCH_OCCURRENCES_PER_WEEK);
    }

    /**
     * @param array<string, mixed> $site
     *
     * @return array{statut: string, label: string, etat: string}
     */
    public function decide(array $site, ?int $dureeJours = null): array
    {
        $duree = max(1, (int) ($dureeJours ?? $site['duree_jours'] ?? self::BASE_DAYS));
        $criticalThreshold = $this->getCriticalThreshold($duree);
        $watchThreshold = $this->getWatchThreshold($duree);
        $etat = $this->decideEtat($site);

        if ($etat === self::CAPACITY_CHECK_CODE) {
            return ['statut' => '', 'label' => '', 'etat' => $etat];
        }

        if ($etat === self::RISK_CONGESTION_CODE) {
            return [
                'statut' => 'SOUS_OBSERVATION',
                'label' => 'SOUS OBSERVATION',
                'etat' => $etat,
            ];
        }

        // TF + 10G : état et statut toujours OK, occurrences non significatives
        $cls = strtoupper(preg_replace('/\s+/u', '', (string) ($site['classification'] ?? '')));
        $capacite = (float) ($site['capacite_mbps'] ?? 0);
        if ($cls === 'TF' && $capacite > 0 && abs($capacite - self::CAPACITE_10G_MBPS) < 1.0) {
            return ['statut' => 'OK', 'label' => 'OK', 'etat' => 'OK'];
        }

        $criticalSources = $this->getOccurrenceSourcesAtThreshold($site, $criticalThreshold);
        if ($criticalSources !== []) {
            return [
                'statut' => 'CRITIQUE',
                'label' => $this->formatStatutLabel('CRITIQUE', $criticalSources),
                'etat' => $etat,
            ];
        }

        $watchSources = $this->getOccurrenceSourcesAtThreshold($site, $watchThreshold);
        if ($watchSources !== []) {
            return [
                'statut' => 'SOUS_OBSERVATION',
                'label' => $this->formatStatutLabel('SOUS OBSERVATION', $watchSources),
                'etat' => $etat,
            ];
        }

        return ['statut' => 'OK', 'label' => 'OK', 'etat' => $etat];
    }

    /**
     * @param array<string, mixed> $site
     */
    public function decideEtat(array $site): string
    {
        if ($this->isUnknownType($site)) {
            return self::CAPACITY_CHECK_CODE;
        }

        $cls = strtoupper(preg_replace('/\s+/u', '', (string) ($site['classification'] ?? '')));
        $capacite = (float) ($site['capacite_mbps'] ?? 0);
        $occurrences = (int) ($site['nombre_occurrences'] ?? 0);
        $est10G = $capacite > 0 && abs($capacite - self::CAPACITE_10G_MBPS) < 1.0;

        // TF + 10G : la capacité limitée à 10G absorbe les occurrences → toujours OK
        if ($cls === 'TF' && $est10G) {
            return 'OK';
        }

        if (str_contains($cls, 'COTRANS')) {
            return $this->decideEtatCotrans($site, $est10G);
        }

        $tauxUtilisation = $this->extractUtilizationRate($site);

        if ($tauxUtilisation !== null
            && $tauxUtilisation >= self::CONGESTION_RATE_THRESHOLD
            && $occurrences < self::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return self::RISK_CONGESTION_CODE;
        }

        // Non-TF, capacité = 10G : BRIDAGE si taux >= 90% et occ >= 50
        if ($cls !== 'TF' && $est10G
            && $tauxUtilisation !== null && $tauxUtilisation >= self::CONGESTION_RATE_THRESHOLD
            && $occurrences >= self::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return 'BRIDAGE';
        }

        if ($tauxUtilisation !== null && $tauxUtilisation >= self::CONGESTION_RATE_THRESHOLD && $occurrences >= self::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return 'CONGESTION';
        }

        if ($tauxUtilisation !== null && $tauxUtilisation < self::CONGESTION_RATE_THRESHOLD && $occurrences >= self::CRITICAL_OCCURRENCES_PER_WEEK) {
            return 'BRIDAGE';
        }

        if (!$this->isBhType($site) && $this->hasAnyRateAbove($site, self::CAPACITY_CHECK_RATE_THRESHOLD)) {
            return self::CAPACITY_CHECK_CODE;
        }

        return 'OK';
    }

    /**
     * État pour les sites COTRANS / NO_COTRANS.
     * - Capacité 10G non-TF : BRIDAGE(FDD) / BRIDAGE(TDD) / BRIDAGE
     * - Autres : CONGESTION(FDD) / CONGESTION(TDD) / CONGESTION
     * - Fallback global si taux_utilisation dispo : CONGESTION standard
     *
     * @param array<string, mixed> $site
     */
    private function decideEtatCotrans(array $site, bool $est10G): string
    {
        $tauxFdd  = $this->extractRateByKey($site, 'taux_utilisation_fdd');
        $tauxTdd  = $this->extractRateByKey($site, 'taux_utilisation_tdd');
        $occFdd   = (int) ($site['nombre_occurrences_fdd'] ?? 0);
        $occTdd   = (int) ($site['nombre_occurrences_tdd'] ?? 0);
        $occTotal = (int) ($site['nombre_occurrences'] ?? 0);

        $fddCrit = $tauxFdd !== null && $tauxFdd >= self::CONGESTION_RATE_THRESHOLD && $occFdd >= self::RISK_CONGESTION_OCCURRENCES_LIMIT;
        $tddCrit = $tauxTdd !== null && $tauxTdd >= self::CONGESTION_RATE_THRESHOLD && $occTdd >= self::RISK_CONGESTION_OCCURRENCES_LIMIT;

        // Fallback : taux global >= 90% et occ total >= 50 → CONGESTION
        $tauxGlobal = $this->extractUtilizationRate($site);
        if ($tauxGlobal !== null && $tauxGlobal >= self::CONGESTION_RATE_THRESHOLD && $occTotal >= self::RISK_CONGESTION_OCCURRENCES_LIMIT) {
            return 'CONGESTION';
        }

        if ($est10G) {
            // 10G non-TF : seuil = bridage
            if ($fddCrit && $tddCrit) return 'BRIDAGE';
            if ($fddCrit)            return 'BRIDAGE(FDD)';
            if ($tddCrit)            return 'BRIDAGE(TDD)';
        } else {
            if ($fddCrit && $tddCrit) return 'CONGESTION';
            if ($fddCrit)             return 'CONGESTION(FDD)';
            if ($tddCrit)             return 'CONGESTION(TDD)';
        }

        if (($tauxGlobal !== null && $tauxGlobal >= self::CONGESTION_RATE_THRESHOLD && $occTotal < self::RISK_CONGESTION_OCCURRENCES_LIMIT)
            || ($tauxFdd !== null && $tauxFdd >= self::CONGESTION_RATE_THRESHOLD && $occFdd < self::RISK_CONGESTION_OCCURRENCES_LIMIT)
            || ($tauxTdd !== null && $tauxTdd >= self::CONGESTION_RATE_THRESHOLD && $occTdd < self::RISK_CONGESTION_OCCURRENCES_LIMIT)) {
            return self::RISK_CONGESTION_CODE;
        }

        return 'OK';
    }

    /**
     * @param array<string, mixed> $site
     */
    private function extractRateByKey(array $site, string $key): ?float
    {
        if (!array_key_exists($key, $site)) {
            return null;
        }
        $value = $site[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $n = str_replace([',', '%', ' '], ['.', '', ''], trim($value));
            return is_numeric($n) ? round((float) $n, 2) : null;
        }
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * @param array<string, mixed> $site
     */
    public function extractUtilizationRate(array $site): ?float
    {
        if (!array_key_exists('taux_utilisation', $site)) {
            return null;
        }

        $value = $site['taux_utilisation'];
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $normalized = str_replace(['%', ' '], '', trim($value));
            $normalized = str_replace(',', '.', $normalized);
            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }

            return (float) $normalized;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @param array<string, mixed> $site
     *
     * @return list<string>
     */
    private function getOccurrenceSourcesAtThreshold(array $site, int $threshold): array
    {
        $sources = [];

        if ((int) ($site['nombre_occurrences_tdd'] ?? 0) >= $threshold) {
            $sources[] = 'TDD';
        }

        if ((int) ($site['nombre_occurrences_fdd'] ?? 0) >= $threshold) {
            $sources[] = 'FDD';
        }

        if ($sources === [] && (int) ($site['nombre_occurrences'] ?? 0) >= $threshold) {
            $sources[] = 'TOTAL';
        }

        return $sources;
    }

    /**
     * @param list<string> $sources
     */
    private function formatStatutLabel(string $baseLabel, array $sources): string
    {
        if ($sources === [] || $sources === ['TOTAL']) {
            return $baseLabel;
        }

        return sprintf('%s (%s)', $baseLabel, implode('/', $sources));
    }

    /**
     * Retourne true si le type de transmission est inconnu / non défini.
     *
     * @param array<string, mixed> $site
     */
    private function isUnknownType(array $site): bool
    {
        $type = strtoupper(trim((string) ($site['type_trans'] ?? '')));

        return $type === '' || in_array($type, ['NON_DEFINI', 'NONE', 'N_A', 'NA', '-'], true);
    }

    /**
     * Retourne true si le type du site est de la famille BH (BH, BH_TT, BH_OO, BACKBONE).
     *
     * @param array<string, mixed> $site
     */
    private function isBhType(array $site): bool
    {
        $type = strtoupper(trim((string) ($site['type_trans'] ?? '')));

        return str_starts_with($type, 'BH') || $type === 'BACKBONE';
    }

    /**
     * Retourne true si au moins l'un des taux (global, TDD, FDD) dépasse le seuil donné.
     *
     * @param array<string, mixed> $site
     */
    private function hasAnyRateAbove(array $site, float $threshold): bool
    {
        foreach (['taux_utilisation', 'taux_utilisation_tdd', 'taux_utilisation_fdd'] as $key) {
            $rate = $this->extractRateByKey($site, $key);
            if ($rate !== null && $rate >= $threshold) {
                return true;
            }
        }

        return false;
    }

}
