<?php

namespace App\Controller;

use App\Repository\UmptSiteRepository;
use App\Service\UmptImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/import/umpt')]
class UmptImportController extends AbstractController
{
    #[Route('', name: 'import_umpt', methods: ['GET'])]
    public function index(UmptSiteRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        return $this->render('import/umpt.html.twig', [
            'user' => $this->getUser(),
            'umptSites' => $repository->findBy([], ['updatedAt' => 'DESC'], 20),
            'totalUmptSites' => $repository->count([]),
        ]);
    }

    #[Route('', name: 'import_umpt_post', methods: ['POST'])]
    public function import(Request $request, UmptImportService $importService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $file = $request->files->get('umpt_file');
        if (!$file) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier Excel UMPT.');
            return $this->redirectToRoute('import_umpt');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $this->addFlash('error', 'Format non supporté. Utilisez xlsx, xls ou csv.');
            return $this->redirectToRoute('import_umpt');
        }

        try {
            $result = $importService->import($file);
            $this->addFlash('success', sprintf(
                'Import UMPT terminé. Nouveaux: %d | Mis à jour: %d | Ignorés: %d',
                $result['created'],
                $result['updated'],
                $result['ignored']
            ));
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Erreur import UMPT: ' . $exception->getMessage());
        }

        return $this->redirectToRoute('import_umpt');
    }
}
