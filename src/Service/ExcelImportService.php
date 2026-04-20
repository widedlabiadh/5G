<?php
// src/Service/ExcelImportService.php

namespace App\Service;

use App\Entity\AnalyseResultat;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    private EntityManagerInterface $em;
    private GeocodingService $geocodingService;
    
    public function __construct(EntityManagerInterface $em, GeocodingService $geocodingService)
    {
        $this->em = $em;
        $this->geocodingService = $geocodingService;
    }
    
    public function import(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        
        $headers = array_shift($rows);
        $imported = 0;
        $errors = [];
        $dateAnalyse = new \DateTime();
        
        foreach ($rows as $index => $row) {
            if (empty($row[0]) || empty(trim($row[0]))) {
                continue;
            }
            
            try {
                // Vérifier si le site existe déjà
                $existingSite = $this->em->getRepository(AnalyseResultat::class)
                    ->findOneBy(['site' => trim($row[0])]);
                
                if ($existingSite) {
                    $analyseResultat = $existingSite;
                } else {
                    $analyseResultat = new AnalyseResultat();
                    $analyseResultat->setSite(trim($row[0]));
                }
                
                // Remplir les données
                $analyseResultat->setClassification(!empty($row[1]) ? trim($row[1]) : 'NON_DEFINI');
                $analyseResultat->setTypeTrans(!empty($row[2]) ? trim($row[2]) : 'NON_DEFINI');
                $analyseResultat->setMaxTrafic(!empty($row[3]) ? (float)$row[3] : 0);
                $analyseResultat->setSeuilCritique(!empty($row[4]) ? (float)$row[4] : 100);
                $analyseResultat->setNombreOccurrences(!empty($row[5]) ? (int)$row[5] : 0);
                $analyseResultat->setTotalMeasures(!empty($row[6]) ? (int)$row[6] : 0);
                $analyseResultat->setDateAnalyse($dateAnalyse);
                
                // Générer les coordonnées GPS si non existantes
                if (!$analyseResultat->getLatitude() && !$analyseResultat->getLongitude()) {
                    $gouvernorat = $this->extractGovernorate($analyseResultat->getSite());
                    $coords = $this->geocodingService->getDefaultCoordinatesForGovernorate($gouvernorat);
                    $analyseResultat->setLatitude($coords[0]);
                    $analyseResultat->setLongitude($coords[1]);
                }
                
                $this->em->persist($analyseResultat);
                $imported++;
                
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage()
                ];
            }
        }
        
        if ($imported > 0) {
            $this->em->flush();
        }
        
        return [
            'imported' => $imported,
            'errors' => $errors,
            'total_rows' => count($rows)
        ];
    }
    
    private function extractGovernorate(string $siteName): string
    {
        $governorates = ['Tunis', 'Ariana', 'Ben Arous', 'Manouba', 'Nabeul', 'Zaghouan', 
                         'Bizerte', 'Béja', 'Jendouba', 'Le Kef', 'Siliana', 'Sousse', 
                         'Monastir', 'Mahdia', 'Sfax', 'Kairouan', 'Kasserine', 'Sidi Bouzid', 
                         'Gabès', 'Médenine', 'Tataouine', 'Gafsa', 'Tozeur', 'Kebili'];
        
        foreach ($governorates as $gov) {
            if (stripos($siteName, $gov) !== false) {
                return $gov;
            }
        }
        return 'Tunis';
    }
}