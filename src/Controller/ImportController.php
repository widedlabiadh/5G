<?php

namespace App\Controller;

use App\Entity\AnalyseResultat;
use App\Entity\CapaciteSite;
use App\Service\Import\ImportDispatcher;
use App\Service\Python\PythonApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ImportController extends AbstractController
{
    private const BATCH_SIZE = 500;

    private ImportDispatcher $dispatcher;
    private PythonApiClient $pythonClient;

    public function __construct(ImportDispatcher $dispatcher, PythonApiClient $pythonClient)
    {
        $this->dispatcher = $dispatcher;
        $this->pythonClient = $pythonClient;
    }

    // ============================================================
    // IMPORT EXCEL PRINCIPAL
    // ============================================================
    #[Route('/import/excel', name: 'import_excel', methods: ['GET'])]
    public function importExcel(): Response
    {
        return $this->render('import/excel.html.twig', ['user' => $this->getUser()]);
    }

    #[Route('/import/excel-post', name: 'import_excel_post', methods: ['POST'])]
    public function importExcelPost(Request $request): JsonResponse
    {
        $typeDonnees = $request->request->get('type_donnees');

        try {
            if ($typeDonnees === 'trans') {
                $traffic = $request->files->get('traffic_file');
                if (!$traffic) {
                    return $this->json(['success' => false, 'message' => 'Fichier trafic obligatoire.'], 400);
                }
                
                $port = $request->files->get('port_file');
                $liaison = $request->files->get('liaison_file');
                $gps = $request->files->get('gps_file');
                
                $result = $this->dispatcher->handleTransmission($traffic, $port, $liaison, $gps);
                $message = $result['message'] ?? $result['error'] ?? 'Erreur inconnue pendant l\'import.';
                
                if ($result['success'] ?? false) {
                    return $this->json(['success' => true, 'message' => $message]);
                }
                return $this->json(['success' => false, 'message' => $message], 500);
            }

            if ($typeDonnees === 'dropcong') {
                $drop1 = $request->files->get('drop1_file');
                $drop2 = $request->files->get('drop2_file');
                if (!$drop1) {
                    return $this->json(['success' => false, 'message' => 'Fichier DropCong obligatoire.'], 400);
                }
                $result = $this->dispatcher->handleDropCong($drop1, $drop2);
                $message = $result['message'] ?? $result['error'] ?? 'Erreur inconnue pendant l\'import DropCong.';

                if ($result['success'] ?? false) {
                    return $this->json(['success' => true, 'message' => $message]);
                }
                return $this->json(['success' => false, 'message' => $message], 500);
            }

            if ($typeDonnees === 'synch') {
                $files = $request->files->get('synch_files');
                if (empty($files)) {
                    return $this->json(['success' => false, 'message' => 'Aucun fichier sélectionné.'], 400);
                }
                $result = $this->dispatcher->handleSynch($files);
                return $this->json(['success' => true, 'message' => $result['message']]);
            }

            return $this->json(['success' => false, 'message' => 'Type non reconnu.'], 400);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ============================================================
    // IMPORT CAPACITÉS ULTRA RAPIDE (SANS REGEXP)
    // ============================================================
    #[Route('/import/capacite', name: 'import_capacite', methods: ['GET'])]
    public function importCapacite(): Response
    {
        return $this->render('import/capacite.html.twig', ['user' => $this->getUser()]);
    }

    #[Route('/import/capacite/fo', name: 'import_capacite_fo', methods: ['POST'])]
    public function importCapaciteFO(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUploadFast($request, $em, 'FO');
    }

    #[Route('/import/capacite/fh', name: 'import_capacite_fh', methods: ['POST'])]
    public function importCapaciteFH(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUploadFast($request, $em, 'FH');
    }

    #[Route('/import/capacite/backbone', name: 'import_capacite_backbone', methods: ['POST'])]
    public function importCapaciteBackbone(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUploadFast($request, $em, 'BACKBONE');
    }

    #[Route('/import/capacite/multiple', name: 'import_capacite_multiple', methods: ['POST'])]
    public function importCapaciteMultiple(Request $request, EntityManagerInterface $em): Response
    {
        $files = $request->files->get('capacite_files');
        $types = $request->request->all('types');
        if (!$files || empty($files)) {
            $this->addFlash('error', 'Veuillez sélectionner au moins un fichier');
            return $this->redirectToRoute('import_capacite');
        }
        if (!is_array($files)) {
            $files = [$files];
        }

        $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        try {
            for ($i = 0; $i < count($files); $i++) {
                $file = $files[$i];
                $type = $types[$i] ?? null;
                if (!$file || !$type) {
                    continue;
                }

                $ext = $file->getClientOriginalExtension();
                if (!in_array($ext, ['xlsx', 'xls'])) {
                    continue;
                }

                $tempPath = $tempDir . uniqid() . '.' . $ext;
                $file->move($tempDir, basename($tempPath));
                try {
                    $result = $this->pythonClient->importCapacite(new UploadedFile($tempPath, $file->getClientOriginalName()), $type);
                    if ($result['status'] === 'success') {
                        $this->batchInsertCapacitiesFast($em, $result['data']['capacites'] ?? [], $type, $file->getClientOriginalName());
                    }
                } finally {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }
            }
            $this->addFlash('success', "✅ Capacités importées avec succès !");
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ ' . $e->getMessage());
        }
        return $this->redirectToRoute('import_capacite');
    }

    private function processCapaciteUploadFast(Request $request, EntityManagerInterface $em, string $typeTrans): Response
    {
        $file = $request->files->get('capacite_file');
        if (!$file) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier');
            return $this->redirectToRoute('import_capacite');
        }
        $ext = $file->getClientOriginalExtension();
        if (!in_array($ext, ['xlsx', 'xls'])) {
            $this->addFlash('error', 'Format non supporté');
            return $this->redirectToRoute('import_capacite');
        }

        $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $tempPath = $tempDir . uniqid() . '.' . $ext;
        $file->move($tempDir, basename($tempPath));

        try {
            $result = $this->pythonClient->importCapacite(new UploadedFile($tempPath, $file->getClientOriginalName()), $typeTrans);
            if ($result['status'] === 'success') {
                $count = $this->batchInsertCapacitiesFast($em, $result['data']['capacites'] ?? [], $typeTrans, $file->getClientOriginalName());
                $this->addFlash('success', "✅ Capacités {$typeTrans} importées ! ($count sites)");
            } else {
                $this->addFlash('error', '❌ ' . ($result['message'] ?? 'Erreur API'));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ ' . $e->getMessage());
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
        return $this->redirectToRoute('import_capacite');
    }

    /**
     * Insertion groupée ULTRA RAPIDE - pas de REGEXP, pas de UPDATE individuels
     */
    private function batchInsertCapacitiesFast(EntityManagerInterface $em, array $capacites, string $typeTrans, string $sourceFilename): int
    {
        if (empty($capacites)) {
            return 0;
        }

        // Préparer les données
        $rows = [];
        foreach ($capacites as $capData) {
            if (!is_array($capData)) {
                continue;
            }
            $siteCode = strtoupper(trim((string) ($capData['site'] ?? '')));
            $capacite = (float) ($capData['capacite_mbps'] ?? 0);
            if ($siteCode === '' || $capacite <= 0) {
                continue;
            }
            $type = strtoupper(trim((string) ($capData['type_trans'] ?? $typeTrans)));
            $rows[] = [
                'site' => $siteCode,
                'type' => $type,
                'capacite' => $capacite,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            $count = 0;
            $chunks = array_chunk($rows, self::BATCH_SIZE);
            $importDate = (new \DateTime())->format('Y-m-d H:i:s');

            foreach ($chunks as $chunk) {
                $values = [];
                $params = [];
                $i = 0;

                foreach ($chunk as $row) {
                    $values[] = "(:site{$i}, :type{$i}, :capacite{$i}, :date{$i}, :source{$i})";
                    $params["site{$i}"] = $row['site'];
                    $params["type{$i}"] = $row['type'];
                    $params["capacite{$i}"] = $row['capacite'];
                    $params["date{$i}"] = $importDate;
                    $params["source{$i}"] = $sourceFilename;
                    $i++;
                }

                $sql = "INSERT INTO capacite_site (site, type_trans, capacite_mbps, date_mise_ajour, source_fichier) 
                        VALUES " . implode(',', $values) . "
                        ON CONFLICT (site, type_trans) 
                        DO UPDATE SET 
                            capacite_mbps = EXCLUDED.capacite_mbps,
                            date_mise_ajour = EXCLUDED.date_mise_ajour,
                            source_fichier = EXCLUDED.source_fichier";
                
                $conn->executeStatement($sql, $params);
                $count += count($chunk);
            }

            $conn->commit();
            return $count;

        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ============================================================
    // AUTRES ROUTES
    // ============================================================
    #[Route('/import/historique', name: 'import_historique', methods: ['GET'])]
    public function historique(EntityManagerInterface $em): Response
    {
        $analyses = $em->getRepository(AnalyseResultat::class)->findBy([], ['dateAnalyse' => 'DESC'], 100);
        return $this->render('import/historique.html.twig', ['analyses' => $analyses]);
    }

    #[Route('/courbe/trafic', name: 'courbe_trafic', methods: ['GET'])]
    public function courbe(): Response
    {
        return $this->render('courbe/trafic.html.twig', ['user' => $this->getUser(), 'roleLabel' => 'Superuser']);
    }

    #[Route('/api/python/health', name: 'api_python_health', methods: ['GET'])]
    public function pythonHealth(): JsonResponse
    {
        if ($this->pythonClient->isAvailable()) {
            return $this->json(['status' => 'ok', 'message' => 'API Python disponible']);
        }
        return $this->json(['status' => 'error', 'message' => 'API Python hors ligne'], 503);
    }
}