<?php
// src/Service/GeocodingService.php

namespace App\Service;

class GeocodingService
{
    // Coordonnées par site (tu pourras les importer depuis la base plus tard)
    private static array $siteCoordinates = [];

    /**
     * Charge les coordonnées depuis un tableau (utilisé par l'import)
     */
    public static function setSiteCoordinates(array $coordinates): void
    {
        self::$siteCoordinates = $coordinates;
    }

    /**
     * Récupère les coordonnées d'un site par son nom
     */
    public function getCoordinatesFromSiteName(string $siteName): array
    {
        // Vérifier dans le cache
        if (isset(self::$siteCoordinates[$siteName])) {
            return self::$siteCoordinates[$siteName];
        }

        // Sinon, extraire le préfixe (3 premières lettres)
        $prefix = strtoupper(substr($siteName, 0, 3));
        
        $defaultCoords = ['latitude' => 33.8, 'longitude' => 9.5];
        
        // Coordonnées par préfixe (gouvernorats)
        $prefixMap = [
            'TUN' => [36.8065, 10.1815], // Tunis
            'ARI' => [36.8601, 10.1934], // Ariana
            'BEN' => [36.7539, 10.2186], // Ben Arous
            'MAN' => [36.8081, 10.0956], // Manouba
            'NAB' => [36.4561, 10.7376], // Nabeul
            'ZAG' => [36.4029, 10.1427], // Zaghouan
            'BIZ' => [37.2744,  9.8739], // Bizerte
            'BEJ' => [36.7256,  9.1817], // Béja
            'JEN' => [36.5014,  8.7803], // Jendouba
            'KEF' => [36.1741,  8.7049], // Le Kef
            'SIL' => [36.0850,  9.3705], // Siliana
            'SOU' => [35.8256, 10.6364], // Sousse
            'MON' => [35.7779, 10.8262], // Monastir
            'MAH' => [35.5041, 11.0622], // Mahdia
            'SFA' => [34.7406, 10.7603], // Sfax
            'KAI' => [35.6781, 10.0964], // Kairouan
            'KAS' => [35.1676,  8.8365], // Kasserine
            'SIB' => [35.0382,  9.4858], // Sidi Bouzid
            'GAB' => [33.8815, 10.0982], // Gabès
            'MED' => [33.3548, 10.5055], // Médenine
            'TAT' => [32.9296, 10.4518], // Tataouine
            'GAF' => [34.4250,  8.7842], // Gafsa
            'TOZ' => [33.9197,  8.1336], // Tozeur
            'KEB' => [33.7044,  8.9690], // Kebili
        ];
        
        if (isset($prefixMap[$prefix])) {
            [$lat, $lng] = $prefixMap[$prefix];
            return ['latitude' => $lat, 'longitude' => $lng];
        }
        
        return $defaultCoords;
    }
}