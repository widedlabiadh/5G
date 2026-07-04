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
            'count' => $this->alertService->countToutesAlertes(),
            'non_lues' => $this->alertService->countAlertesNonLues(),
            'alertes' => $this->alertService->getAlertesNonLues()
        ]);
    }

    #[Route('/alertes', name: 'alertes_liste', methods: ['GET'])]
    public function liste(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $alertes = $this->alertService->getHistoriqueAlertes(1000);
        $sitesSansType = array_map(
            static function (array $row): array {
                return [
                    'id' => 'sans-type-' . $row['id'],
                    'site' => $row['site'],
                    'etat' => 'SANS_TYPE',
                    'trafic_j' => 0,
                    'capacite_mbps' => 0,
                    'taux_utilisation' => 0,
                    'type_trans' => $row['type_trans'] ?? 'NON_DEFINI',
                    'classification' => $row['classification'] ?? 'NON_DEFINI',
                    'message' => sprintf('Site Type %s %s', $row['site'], $row['type_trans'] ?? 'NON_DEFINI'),
                    'date_alerte' => $row['date_analyse'] ?? null,
                    'alerte_lue' => true,
                    'email_envoye' => false,
                ];
            },
            $this->alertService->getSitesSansType(500)
        );

        $historiqueByKey = [];

        foreach (array_merge($alertes, $sitesSansType) as $row) {
            $key = ($row['site'] ?? '') . '|' . ($row['etat'] ?? '');
            if ($key === '|') {
                continue;
            }
            $historiqueByKey[$key] = $row;
        }

        $historique = array_values($historiqueByKey);
        usort($historique, static function (array $left, array $right): int {
            $leftDate = $left['date_alerte'] ?? '';
            $rightDate = $right['date_alerte'] ?? '';
            return strcmp((string) $rightDate, (string) $leftDate);
        });

        return $this->render('alertes/index.html.twig', [
            'alertes' => $this->alertService->getAlertesNonLues(),
            'total_non_lues' => $this->alertService->countAlertesNonLues(),
            'total_toutes_alertes' => count($historique),
            'total_sans_type' => $this->alertService->countSitesSansType(),
            'historique' => $historique,
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
