<?php
// src/Service/GeocodingService.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeocodingService
{
    private HttpClientInterface $httpClient;
    
    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }
    
    /**
     * Convertit une adresse en coordonnées GPS (latitude/longitude)
     * Utilise l'API Nominatim (OpenStreetMap) - Gratuite
     */
    public function geocodeAddress(string $address): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($address . ', Tunisie') . '&format=json&limit=1';
        
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => 'NetWON Monitoring/1.0']
            ]);
            
            $data = $response->toArray();
            
            if (!empty($data)) {
                return [
                    'latitude' => floatval($data[0]['lat']),
                    'longitude' => floatval($data[0]['lon'])
                ];
            }
        } catch (\Exception $e) {
            // Log erreur
        }
        
        return null;
    }
    
    /**
     * Retourne des coordonnées par défaut pour la Tunisie
     * si le géocodage échoue
     */
    public function getDefaultCoordinatesForGovernorate(string $gouvernorat): array
    {
        $coordinates = [
            'Tunis' => [36.8065, 10.1815],
            'Ariana' => [36.8601, 10.1934],
            'Ben Arous' => [36.7539, 10.2186],
            'Manouba' => [36.8081, 10.0956],
            'Nabeul' => [36.4561, 10.7376],
            'Zaghouan' => [36.4029, 10.1427],
            'Bizerte' => [37.2744, 9.8739],
            'Béja' => [36.7256, 9.1817],
            'Jendouba' => [36.5014, 8.7803],
            'Le Kef' => [36.1741, 8.7049],
            'Siliana' => [36.0850, 9.3705],
            'Sousse' => [35.8256, 10.6364],
            'Monastir' => [35.7779, 10.8262],
            'Mahdia' => [35.5041, 11.0622],
            'Sfax' => [34.7406, 10.7603],
            'Kairouan' => [35.6781, 10.0964],
            'Kasserine' => [35.1676, 8.8365],
            'Sidi Bouzid' => [35.0382, 9.4858],
            'Gabès' => [33.8815, 10.0982],
            'Médenine' => [33.3548, 10.5055],
            'Tataouine' => [32.9296, 10.4518],
            'Gafsa' => [34.4250, 8.7842],
            'Tozeur' => [33.9197, 8.1336],
            'Kebili' => [33.7044, 8.9690]
        ];
        
        return $coordinates[$gouvernorat] ?? [36.8065, 10.1815];
    }
}