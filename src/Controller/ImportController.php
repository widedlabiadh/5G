<?php
// src/Controller/ImportController.php
namespace App\Controller;

use App\Entity\AnalyseResultat;
use App\Service\FastApiPortDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class ImportController extends AbstractController
{
    private FastApiPortDetector $portDetector;
    public function __construct(FastApiPortDetector $portDetector)
    {
        $this->portDetector = $portDetector;
    }
    // ============================================================
    // ROUTE PRINCIPALE - AFFICHAGE DU FORMULAIRE
    // ============================================================
    #[Route('/import/excel', name: 'import_excel', methods: ['GET'])]
    public function importExcel(): Response
    {
        return $this->render('import/excel.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    // ============================================================
    // ROUTE AJAX POST - TRAITEMENT DES FICHIERS
    // ============================================================
    #[Route('/import/excel-post', name: 'import_excel_post', methods: ['POST'])]
    public function importExcelPost(Request $request, EntityManagerInterface $em): JsonResponse
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');

        $typeDonnees = $request->request->get('type_donnees');

        $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        try {
            // ============================================================
            // CAS 1 : TRANSMISSION (3 fichiers → Python)
            // ============================================================
            if ($typeDonnees === 'trans') {
                $trafficFile = $request->files->get('traffic_file');
                $liaisonFile = $request->files->get('liaison_file');
                $portFile    = $request->files->get('port_file');

                if (!$trafficFile || !$liaisonFile || !$portFile) {
                    return $this->json(['success' => false, 'message' => 'Veuillez sélectionner les 3 fichiers requis.'], 400);
                }

                // Vérification taille (50MB max par fichier)
                foreach ([$trafficFile, $liaisonFile, $portFile] as $file) {
                    if ($file->getSize() > 50 * 1024 * 1024) {
                        return $this->json(['success' => false, 'message' => 'Un fichier dépasse la limite de 50MB.'], 400);
                    }
                }

                // --------------------------------------------------------
                // 🚀 DÉTECTION AUTO DU PORT FASTAPI
                // --------------------------------------------------------
                $apiUrl = $this->portDetector->getApiUrl();
                $pythonOk = false;
                $warning = null;
                $resultats = null;

                if ($apiUrl === null) {
                    $warning = '⚠️ API Python introuvable. Vérifie que FastAPI tourne sur un des ports : ' . implode(', ', $this->portDetector->getPorts());
                } else {
                    try {
                        $boundary = uniqid('boundary_', true);
                        $body = '';

                        $files = [
                            'trafic'       => [$trafficFile->getPathname(), $trafficFile->getClientOriginalName()],
                            'port'         => [$portFile->getPathname(),    $portFile->getClientOriginalName()],
                            'type_liaison' => [$liaisonFile->getPathname(), $liaisonFile->getClientOriginalName()],
                        ];

                        foreach ($files as $fieldName => [$path, $originalName]) {
                            $fileContent = file_get_contents($path);
                            if ($fileContent === false) {
                                throw new \Exception("Impossible de lire le fichier : $originalName");
                            }
                            $body .= "--$boundary\r\n";
                            $body .= "Content-Disposition: form-data; name=\"$fieldName\"; filename=\"$originalName\"\r\n";
                            $body .= "Content-Type: application/octet-stream\r\n\r\n";
                            $body .= $fileContent . "\r\n";
                        }
                        $body .= "--$boundary--\r\n";

                        $ch = curl_init($apiUrl . '/traiter');
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $body,
                            CURLOPT_HTTPHEADER     => [
                                "Content-Type: multipart/form-data; boundary=$boundary",
                                "Content-Length: " . strlen($body),
                            ],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 300,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                        ]);

                        $rawResponse = curl_exec($ch);
                        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlError   = curl_error($ch);
                        curl_close($ch);

                        if ($curlError) {
                            throw new \Exception('cURL error: ' . $curlError);
                        }

                        $resultats = json_decode($rawResponse, true);

                        if ($httpCode === 200 && isset($resultats['status']) && $resultats['status'] === 'success') {
                            $pythonOk = true;
                            // Stocker les résultats en session
                            $request->getSession()->set('resultats_analyse', $resultats['data']);
                            $request->getSession()->set('stats_analyse',     $resultats['data']['stats']);
                            
                            // ============================================================
                            // ✅ SAUVEGARDE EN BASE DE DONNÉES
                            // ============================================================
                            $dateAnalyse = new \DateTime();
                            
                            // 1. Sauvegarder les sites TF
                            if (isset($resultats['data']['sites_tf']) && is_array($resultats['data']['sites_tf'])) {
                                foreach ($resultats['data']['sites_tf'] as $siteData) {
                                    $analyse = new AnalyseResultat();
                                    $analyse->setSite($siteData['Site']);
                                    $analyse->setClassification('TF');
                                    $analyse->setTypeTrans($siteData['Type_Trans'] ?? 'NON_DEFINI');
                                    $analyse->setMaxTrafic($siteData['Max_Trafic']);
                                    $analyse->setSeuilCritique($siteData['Seuil_Critique']);
                                    $analyse->setNombreOccurrences($siteData['Nombre_Occurrences']);
                                    $analyse->setTotalMeasures($siteData['Total_Measures'] ?? 0);
                                    if (isset($siteData['Date_Max'])) {
                                        $analyse->setDateMax(new \DateTime($siteData['Date_Max']));
                                    }
                                    $analyse->setDateAnalyse($dateAnalyse);
                                    $em->persist($analyse);
                                }
                            }
                            
                            // 2. Sauvegarder les sites COTRANS
                            if (isset($resultats['data']['sites_cotrans']) && is_array($resultats['data']['sites_cotrans'])) {
                                foreach ($resultats['data']['sites_cotrans'] as $siteData) {
                                    $analyse = new AnalyseResultat();
                                    $analyse->setSite($siteData['Site_TDD']);
                                    $analyse->setClassification('COTRANS');
                                    $analyse->setTypeTrans($siteData['Type_Trans'] ?? 'NON_DEFINI');
                                    $analyse->setMaxTrafic($siteData['Max_Trafic_Final']);
                                    $analyse->setSeuilCritique($siteData['Seuil_Critique']);
                                    $analyse->setNombreOccurrences($siteData['Nombre_Occurrences']);
                                    $analyse->setTotalMeasures($siteData['Total_Measures'] ?? 0);
                                    $analyse->setSiteTdd($siteData['Site_TDD']);
                                    $analyse->setSiteFdd($siteData['Site_FDD']);
                                    if (isset($siteData['Date_Max'])) {
                                        $analyse->setDateMax(new \DateTime($siteData['Date_Max']));
                                    }
                                    $analyse->setDateAnalyse($dateAnalyse);
                                    $em->persist($analyse);
                                }
                            }
                            
                            // 3. Sauvegarder les sites NO_COTRANS
                            if (isset($resultats['data']['sites_nocotrans']) && is_array($resultats['data']['sites_nocotrans'])) {
                                foreach ($resultats['data']['sites_nocotrans'] as $siteData) {
                                    $analyse = new AnalyseResultat();
                                    $analyse->setSite($siteData['Site_TDD']);
                                    $analyse->setClassification('NO_COTRANS');
                                    $analyse->setTypeTrans($siteData['Type_Trans'] ?? 'NON_DEFINI');
                                    $analyse->setMaxTrafic($siteData['Max_Trafic']);
                                    $analyse->setSeuilCritique($siteData['Seuil_Critique']);
                                    $analyse->setNombreOccurrences($siteData['Nombre_Occurrences']);
                                    $analyse->setTotalMeasures($siteData['Total_Measures'] ?? 0);
                                    $analyse->setSiteTdd($siteData['Site_TDD']);
                                    $analyse->setSiteFdd($siteData['Site_FDD']);
                                    if (isset($siteData['Date_Max'])) {
                                        $analyse->setDateMax(new \DateTime($siteData['Date_Max']));
                                    }
                                    if (isset($siteData['Somme_FDD_TDD'])) {
                                        $analyse->setSommeFddTdd($siteData['Somme_FDD_TDD']);
                                    }
                                    $analyse->setDateAnalyse($dateAnalyse);
                                    $em->persist($analyse);
                                }
                            }
                            
                            // 4. Sauvegarder les sites NON_TDD
                            if (isset($resultats['data']['sites_nontdd']) && is_array($resultats['data']['sites_nontdd'])) {
                                foreach ($resultats['data']['sites_nontdd'] as $siteData) {
                                    $analyse = new AnalyseResultat();
                                    $analyse->setSite($siteData['Site']);
                                    $analyse->setClassification('NON_TDD');
                                    $analyse->setTypeTrans($siteData['Type_Trans'] ?? 'NON_DEFINI');
                                    $analyse->setMaxTrafic($siteData['Max_Trafic']);
                                    $analyse->setSeuilCritique($siteData['Seuil_Critique']);
                                    $analyse->setNombreOccurrences($siteData['Nombre_Occurrences']);
                                    $analyse->setTotalMeasures($siteData['Total_Measures'] ?? 0);
                                    if (isset($siteData['Date_Max'])) {
                                        $analyse->setDateMax(new \DateTime($siteData['Date_Max']));
                                    }
                                    $analyse->setDateAnalyse($dateAnalyse);
                                    $em->persist($analyse);
                                }
                            }
                            
                            $em->flush();
                            // ============================================================
                            
                        } else {
                            $warning = '⚠️ API Python: ' . ($resultats['message'] ?? ($resultats['detail'] ?? "Erreur HTTP $httpCode"));
                        }

                    } catch (\Exception $e) {
                        $warning = '⚠️ API Python inaccessible : ' . $e->getMessage();
                    }
                }

                // Sauvegarder les fichiers localement (toujours)
                $timestamp = date('Ymd_His');
                $this->moveFileSafely($trafficFile, $uploadDir, $timestamp . '_traffic_' . $trafficFile->getClientOriginalName());
                $this->moveFileSafely($liaisonFile, $uploadDir, $timestamp . '_liaison_' . $liaisonFile->getClientOriginalName());
                $this->moveFileSafely($portFile,    $uploadDir, $timestamp . '_port_'    . $portFile->getClientOriginalName());

                if ($pythonOk && $resultats) {
                    $stats = $resultats['data']['stats'];
                    return $this->json([
                        'success' => true,
                        'message' => sprintf(
                            '✅ Analyse réussie et sauvegardée en BDD ! %d sites traités — %d TF, %d COTRANS, %d NO_COTRANS, %d NON_TDD.',
                            $stats['total_sites'],
                            $stats['sites_tf'],
                            $stats['sites_cotrans'],
                            $stats['sites_nocotrans'],
                            $stats['sites_nontdd']
                        )
                    ]);
                } else {
                    return $this->json([
                        'success' => true,
                        'warning' => true,
                        'message' => '✅ Fichiers sauvegardés localement. ' . ($warning ?? 'Traitement Python non effectué.')
                    ]);
                }
            }

            // ============================================================
            // CAS 2 : DROPCONG (2 fichiers)
            // ============================================================
            elseif ($typeDonnees === 'dropcong') {
                $drop1File = $request->files->get('drop1_file');
                $drop2File = $request->files->get('drop2_file');

                if (!$drop1File || !$drop2File) {
                    return $this->json(['success' => false, 'message' => 'Veuillez sélectionner les 2 fichiers requis.'], 400);
                }

                $timestamp = date('Ymd_His');
                $this->moveFileSafely($drop1File, $uploadDir, $timestamp . '_drop1_' . $drop1File->getClientOriginalName());
                $this->moveFileSafely($drop2File, $uploadDir, $timestamp . '_drop2_' . $drop2File->getClientOriginalName());

                return $this->json(['success' => true, 'message' => '✅ 2 fichiers DropCong importés avec succès !']);
            }

            // ============================================================
            // CAS 3 : SYNCHRONISATION (N fichiers)
            // ============================================================
            elseif ($typeDonnees === 'synch') {
                $synchFiles = $request->files->get('synch_files');

                if (!$synchFiles || count($synchFiles) === 0) {
                    return $this->json(['success' => false, 'message' => 'Veuillez sélectionner au moins un fichier.'], 400);
                }

                $timestamp = date('Ymd_His');
                $count = 0;
                foreach ($synchFiles as $file) {
                    if ($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_OK) {
                        $this->moveFileSafely($file, $uploadDir, $timestamp . '_synch_' . $file->getClientOriginalName());
                        $count++;
                    }
                }

                return $this->json(['success' => true, 'message' => '✅ ' . $count . ' fichier(s) Synchronisation importé(s) !']);
            }

            return $this->json(['success' => false, 'message' => 'Type de données non reconnu.'], 400);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // HEALTH CHECK - Vérifier si Python tourne (avec détection auto)
    // ============================================================
    #[Route('/api/python/health', name: 'api_python_health', methods: ['GET'])]
    public function pythonHealth(): JsonResponse
    {
        $apiUrl = $this->portDetector->getApiUrl();
        
        if ($apiUrl === null) {
            return $this->json([
                'status' => 'error', 
                'message' => 'API Python hors ligne. Aucun port trouvé parmi: ' . implode(', ', $this->portDetector->getPorts())
            ], 503);
        }

        try {
            $ch = curl_init($apiUrl . '/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $raw      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                return $this->json(['status' => 'error', 'message' => 'API Python ne répond pas : ' . $error], 503);
            }

            return $this->json(['status' => 'ok', 'python_api' => json_decode($raw, true), 'port' => $this->portDetector->detectPort()]);

        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => 'API Python hors ligne'], 503);
        }
    }

    // ============================================================
    // ROUTE POUR VOIR L'HISTORIQUE DES ANALYSES
    // ============================================================
    #[Route('/import/historique', name: 'import_historique', methods: ['GET'])]
    public function historique(EntityManagerInterface $em): Response
    {
        $analyses = $em->getRepository(AnalyseResultat::class)->findBy(
            [],
            ['dateAnalyse' => 'DESC'],
            100
        );
        
        return $this->render('import/historique.html.twig', [
            'analyses' => $analyses,
        ]);
    }

    // ============================================================
    // HELPER PRIVÉ
    // ============================================================
    private function moveFileSafely(UploadedFile $file, string $uploadDir, string $newFilename): void
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new FileException('Erreur upload : ' . $file->getErrorMessage());
        }
        $file->move($uploadDir, $newFilename);
    }
}