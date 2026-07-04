<?php
namespace App\Service\Ia;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class AnomalyDetectionService
{
    private HttpClientInterface $http;
    private string $apiUrl;

    public function __construct(HttpClientInterface $http, string $apiUrl = 'http://localhost:8000')
    {
        $this->http = $http;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Détecte les anomalies pour un site spécifique
     */
    public function detectAnomaliesForSite(string $site): array
    {
        try {
            $response = $this->http->request('GET', $this->apiUrl . '/ia/anomalies/site/' . urlencode($site));
            return $response->toArray();
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'total_anomalies' => 0,
                'anomalies' => []
            ];
        }
    }

    /**
     * Détecte les anomalies pour tous les sites
     */
    public function detectAnomaliesForAllSites(bool $insertAlerts = false): array
    {
        try {
            $response = $this->http->request('GET', $this->apiUrl . '/ia/anomalies/tous', [
                'query' => ['inserer_alertes' => $insertAlerts]
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'total_anomalies' => 0,
                'anomalies' => []
            ];
        }
    }

    /**
     * Récupère la liste des sites physiques
     */
    public function getSitesPhysiques(int $limit = 100): array
    {
        try {
            $response = $this->http->request('GET', $this->apiUrl . '/ia/sites', [
                'query' => ['limit' => $limit]
            ]);
            $data = $response->toArray();
            return $data['sites'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Exécute l'analyse quotidienne
     */
    public function runDailyAnalysis(): array
    {
        try {
            $response = $this->http->request('POST', $this->apiUrl . '/ia/anomalies/quotidien');
            return $response->toArray();
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie si l'API IA est disponible
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->http->request('GET', $this->apiUrl . '/health', [
                'timeout' => 3
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }
}