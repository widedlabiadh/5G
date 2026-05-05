<?php
// src/Controller/AlertController.php

namespace App\Controller;

use App\Service\AlertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AlertController extends AbstractController
{
    public function __construct(private AlertService $alertService) {}

    #[Route('/alertes/non-lues', name: 'alertes_non_lues_api', methods: ['GET'])]
    public function getNonLues(Request $request): JsonResponse
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('alertes_liste');
        }
        return $this->json([
            'count' => $this->alertService->countAlertesNonLues(),
            'alertes' => $this->alertService->getAlertesNonLues()
        ]);
    }

    #[Route('/alertes', name: 'alertes_liste', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('alertes/liste.html.twig', [
            'alertes' => $this->alertService->getAlertesNonLues(),
            'total_non_lues' => $this->alertService->countAlertesNonLues(),
            'historique' => $this->alertService->getHistoriqueAlertes(50),
        ]);
    }

    #[Route('/alertes/{id}/lue', name: 'alerte_marquer_lue', methods: ['POST'])]
    public function marquerLue(int $id, Request $request): JsonResponse
    {
        $this->alertService->marquerLue($id);
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'count' => $this->alertService->countAlertesNonLues()]);
        }
        return $this->redirectToRoute('alertes_liste');
    }

    #[Route('/alertes/toutes-lues', name: 'alertes_toutes_lues', methods: ['POST'])]
    public function marquerToutesLues(Request $request): JsonResponse
    {
        $this->alertService->marquerToutesLues();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'count' => 0]);
        }
        return $this->redirectToRoute('alertes_liste');
    }
}