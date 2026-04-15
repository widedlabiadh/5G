<?php
// src/Service/FastApiPortDetector.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FastApiPortDetector
{
    private $httpClient;
    private $ports = [8000, 8002, 8001, 8003, 8004, 8005];
    private $activePort = null;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Détecte le port sur lequel FastAPI tourne
     */
    public function detectPort(): ?int
    {
        if ($this->activePort !== null) {
            return $this->activePort;
        }

        foreach ($this->ports as $port) {
            try {
                $response = $this->httpClient->request('GET', "http://127.0.0.1:{$port}/health", [
                    'timeout' => 2,
                    'max_duration' => 2
                ]);
                
                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray();
                    if (isset($data['status']) && $data['status'] === 'ok') {
                        $this->activePort = $port;
                        return $port;
                    }
                }
            } catch (\Exception $e) {
                // Port non disponible, continuer
                continue;
            }
        }

        return null;
    }

    /**
     * Retourne l'URL complète de l'API FastAPI
     */
    public function getApiUrl(): ?string
    {
        $port = $this->detectPort();
        if ($port !== null) {
            return "http://127.0.0.1:{$port}";
        }
        return null;
    }

    /**
     * Vérifie si FastAPI est accessible
     */
    public function isAvailable(): bool
    {
        return $this->detectPort() !== null;
    }
    
    public function getPorts(): array
{
    return $this->ports;
}
}