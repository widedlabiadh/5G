<?php
// src/Service/PythonApiService.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PythonApiService
{
    private HttpClientInterface $client;
    private string $apiUrl;

    public function __construct(HttpClientInterface $client, string $apiUrl = 'http://localhost:8000')
    {
        $this->client = $client;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Classification (3 fichiers)
     */
    public function processFiles(string $traficPath, string $portPath, string $typePath): array
    {
        $formData = new \Symfony\Component\HttpClient\MultipartFormData();
        $formData->addFile('trafic', $traficPath);
        $formData->addFile('port', $portPath);
        $formData->addFile('type_liaison', $typePath);

        $response = $this->client->request('POST', $this->apiUrl . '/traiter', [
            'body' => $formData,
        ]);

        return $response->toArray();
    }

    /**
     * Import capacité FO
     */
    public function importCapaciteFO(string $filePath): array
    {
        $formData = new \Symfony\Component\HttpClient\MultipartFormData();
        $formData->addFile('fichier', $filePath);

        $response = $this->client->request('POST', $this->apiUrl . '/capacite/fo', [
            'body' => $formData,
        ]);

        return $response->toArray();
    }

    /**
     * Import capacité FH
     */
    public function importCapaciteFH(string $filePath): array
    {
        $formData = new \Symfony\Component\HttpClient\MultipartFormData();
        $formData->addFile('fichier', $filePath);

        $response = $this->client->request('POST', $this->apiUrl . '/capacite/fh', [
            'body' => $formData,
        ]);

        return $response->toArray();
    }

    /**
     * Import capacité BACKBONE
     */
    public function importCapaciteBackbone(string $filePath): array
    {
        $formData = new \Symfony\Component\HttpClient\MultipartFormData();
        $formData->addFile('fichier', $filePath);

        $response = $this->client->request('POST', $this->apiUrl . '/capacite/backbone', [
            'body' => $formData,
        ]);

        return $response->toArray();
    }

    /**
     * Import multiples fichiers capacité (1 à 3 fichiers)
     */
    public function importCapacitesMultiples(array $files, array $types): array
    {
        $formData = new \Symfony\Component\HttpClient\MultipartFormData();
        
        foreach ($files as $index => $filePath) {
            $formData->addFile('fichiers', $filePath);
        }
        
        $formData->add('types', implode(',', $types));

        $response = $this->client->request('POST', $this->apiUrl . '/capacite/multiple', [
            'body' => $formData,
        ]);

        return $response->toArray();
    }
}