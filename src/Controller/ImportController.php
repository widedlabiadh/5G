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
    private ImportDispatcher $dispatcher;
    private PythonApiClient $pythonClient;

    public function __construct(ImportDispatcher $dispatcher, PythonApiClient $pythonClient)
    {
        $this->dispatcher = $dispatcher;
        $this->pythonClient = $pythonClient;
    }

    // ============================================================
    // IMPORT EXCEL PRINCIPAL (transmission, dropcong, synch)
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
                // ✅ Fichier OBLIGATOIRE
                $traffic = $request->files->get('traffic_file');
                if (!$traffic) {
                    return $this->json(['success' => false, 'message' => 'Fichier trafic obligatoire.'], 400);
                }
                
                // ✅ Fichiers OPTIONNELS
                $port    = $request->files->get('port_file');
                $liaison = $request->files->get('liaison_file');
                $gps     = $request->files->get('gps_file');
                
                $result = $this->dispatcher->handleTransmission($traffic, $port, $liaison, $gps);
                
                if ($result['success']) {
                    return $this->json(['success' => true, 'message' => $result['message']]);
                }
                return $this->json(['success' => false, 'message' => $result['message']], 500);
            }

            if ($typeDonnees === 'dropcong') {
                $drop1 = $request->files->get('drop1_file');
                $drop2 = $request->files->get('drop2_file');
                if (!$drop1 || !$drop2) {
                    return $this->json(['success' => false, 'message' => 'Deux fichiers requis.'], 400);
                }
                $result = $this->dispatcher->handleDropCong($drop1, $drop2);
                return $this->json(['success' => true, 'message' => $result['message']]);
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
    // IMPORT CAPACITÉS (FO, FH, BACKBONE, multiple)
    // ============================================================
    #[Route('/import/capacite', name: 'import_capacite', methods: ['GET'])]
    public function importCapacite(): Response
    {
        return $this->render('import/capacite.html.twig', ['user' => $this->getUser()]);
    }

    #[Route('/import/capacite/fo', name: 'import_capacite_fo', methods: ['POST'])]
    public function importCapaciteFO(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUpload($request, $em, 'FO');
    }

    #[Route('/import/capacite/fh', name: 'import_capacite_fh', methods: ['POST'])]
    public function importCapaciteFH(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUpload($request, $em, 'FH');
    }

    #[Route('/import/capacite/backbone', name: 'import_capacite_backbone', methods: ['POST'])]
    public function importCapaciteBackbone(Request $request, EntityManagerInterface $em): Response
    {
        return $this->processCapaciteUpload($request, $em, 'BACKBONE');
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
        if (!is_array($files)) $files = [$files];

        $totalInserted = 0;
        $totalUpdated = 0;
        $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp/';
        if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

        try {
            for ($i = 0; $i < count($files); $i++) {
                $file = $files[$i];
                $type = $types[$i] ?? null;
                if (!$file || !$type) continue;

                $ext = $file->getClientOriginalExtension();
                if (!in_array($ext, ['xlsx', 'xls'])) continue;

                $tempPath = $tempDir . uniqid() . '.' . $ext;
                $file->move($tempDir, basename($tempPath));
                try {
                    $result = $this->pythonClient->importCapacite(new UploadedFile($tempPath, $file->getClientOriginalName()), $type);
                    if ($result['status'] === 'success') {
                        foreach ($result['data']['capacites'] as $capData) {
                            $existing = $em->getRepository(CapaciteSite::class)->findOneBy([
                                'site' => $capData['site'],
                                'typeTrans' => $type
                            ]);
                            if ($existing) {
                                $existing->setCapaciteMbps($capData['capacite_mbps']);
                                $existing->setDateMiseAJour(new \DateTime());
                                $totalUpdated++;
                            } else {
                                $cap = new CapaciteSite();
                                $cap->setSite($capData['site']);
                                $cap->setTypeTrans($type);
                                $cap->setCapaciteMbps($capData['capacite_mbps']);
                                $cap->setSourceFichier($file->getClientOriginalName());
                                $em->persist($cap);
                                $totalInserted++;
                            }
                        }
                        $em->flush();
                    }
                } finally {
                    if (file_exists($tempPath)) unlink($tempPath);
                }
            }
            $this->addFlash('success', "✅ Capacités importées ! Nouvelles: {$totalInserted} | MAJ: {$totalUpdated}");
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ ' . $e->getMessage());
        }
        return $this->redirectToRoute('import_capacite');
    }

    private function processCapaciteUpload(Request $request, EntityManagerInterface $em, string $typeTrans): Response
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
        if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
        $tempPath = $tempDir . uniqid() . '.' . $ext;
        $file->move($tempDir, basename($tempPath));

        $inserted = 0;
        $updated = 0;
        try {
            $result = $this->pythonClient->importCapacite(new UploadedFile($tempPath, $file->getClientOriginalName()), $typeTrans);
            if ($result['status'] === 'success') {
                foreach ($result['data']['capacites'] as $capData) {
                    $existing = $em->getRepository(CapaciteSite::class)->findOneBy([
                        'site' => $capData['site'],
                        'typeTrans' => $typeTrans
                    ]);
                    if ($existing) {
                        $existing->setCapaciteMbps($capData['capacite_mbps']);
                        $existing->setDateMiseAJour(new \DateTime());
                        $updated++;
                    } else {
                        $cap = new CapaciteSite();
                        $cap->setSite($capData['site']);
                        $cap->setTypeTrans($typeTrans);
                        $cap->setCapaciteMbps($capData['capacite_mbps']);
                        $cap->setSourceFichier($file->getClientOriginalName());
                        $em->persist($cap);
                        $inserted++;
                    }
                }
                $em->flush();
                $this->addFlash('success', "✅ Capacités {$typeTrans} importées ! Nouvelles: {$inserted} | MAJ: {$updated}");
            } else {
                $this->addFlash('error', '❌ ' . ($result['message'] ?? 'Erreur API'));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', '❌ ' . $e->getMessage());
        } finally {
            if (file_exists($tempPath)) unlink($tempPath);
        }
        return $this->redirectToRoute('import_capacite');
    }

    // ============================================================
    // AUTRES ROUTES (historique, courbe, health)
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