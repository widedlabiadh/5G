<?php
// src/Controller/TransmissionController.php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TransmissionController extends AbstractController
{
    // ============================================================
    // HELPER : récupère la dernière date d'import
    // ============================================================
    private function getLastImportDate(EntityManagerInterface $em): ?string
    {
        $conn = $em->getConnection();
        return $conn->fetchOne(
            'SELECT DISTINCT date_analyse FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
        ) ?: null;
    }

    // ============================================================
    // HELPER : récupère les sites par type de transmission (dernière importation)
    // ============================================================
    private function getSitesByType(EntityManagerInterface $em, string $type, string $search = '', string $classFilter = ''): array
    {
        $conn = $em->getConnection();
        
        // Récupérer la dernière date d'import
        $lastDate = $this->getLastImportDate($em);
        
        if (!$lastDate) {
            return [];
        }
        
        $qb = 'SELECT
                id,
                site,
                classification,
                type_trans,
                site_tdd,
                site_fdd,
                max_trafic,
                somme_fdd_tdd,
                seuil_critique,
                nombre_occurrences,
                total_measures,
                date_max,
                date_analyse
             FROM analyse_resultat
             WHERE UPPER(type_trans) = :type AND date_analyse = :lastDate';
        
        $params = [
            'type' => strtoupper($type),
            'lastDate' => $lastDate
        ];

        if ($search) {
            $qb .= " AND (LOWER(site) LIKE :search OR LOWER(site_tdd) LIKE :search OR LOWER(site_fdd) LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        if ($classFilter) {
            $qb .= " AND classification = :class";
            $params['class'] = $classFilter;
        }

        $qb .= ' ORDER BY nombre_occurrences DESC, max_trafic DESC';
        
        return $conn->fetchAllAssociative($qb, $params);
    }

    // ============================================================
    // HELPER : stats par type (dernière importation)
    // ============================================================
    private function getStatsByType(EntityManagerInterface $em, string $type): array
    {
        $conn = $em->getConnection();
        $t    = strtoupper($type);
        
        // Récupérer la dernière date d'import
        $lastDate = $this->getLastImportDate($em);
        
        if (!$lastDate) {
            return [
                'total' => 0,
                'total_tf' => 0,
                'total_cotrans' => 0,
                'total_nocotrans' => 0,
                'total_nontdd' => 0,
                'total_occ' => 0,
                'max_trafic' => 0,
                'sites_critiques' => 0,
            ];
        }

        return [
            'total'           => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND date_analyse = ?', [$t, $lastDate]),
            'total_tf'        => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ? AND date_analyse = ?', [$t, 'TF', $lastDate]),
            'total_cotrans'   => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ? AND date_analyse = ?', [$t, 'COTRANS', $lastDate]),
            'total_nocotrans' => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ? AND date_analyse = ?', [$t, 'NO_COTRANS', $lastDate]),
            'total_nontdd'    => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ? AND date_analyse = ?', [$t, 'NON_TDD', $lastDate]),
            'total_occ'       => (int)$conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND date_analyse = ?', [$t, $lastDate]),
            'max_trafic'      => (float)$conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND date_analyse = ?', [$t, $lastDate]),
            'sites_critiques' => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND nombre_occurrences > 50 AND date_analyse = ?', [$t, $lastDate]),
        ];
    }

    // ============================================================
    // FIBRE OPTIQUE
    // ============================================================
    #[Route('/superuser/fo', name: 'superuser_fo')]
    public function fo(EntityManagerInterface $em, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        
        // Récupérer la dernière date d'import
        $lastDate = $this->getLastImportDate($em);
        
        $sites = $this->getSitesByType($em, 'FO', $search, $classFilter);
        $stats = $this->getStatsByType($em, 'FO');

        return $this->render('dashboard/_transmission_table.html.twig', [
            'user'         => $this->getUser(),
            'sites'        => $sites,
            'stats'        => $stats,
            'type'         => 'FO',
            'titre'        => 'Fibre Optique',
            'search'       => $search,
            'classFilter'  => $classFilter,
            'derniere_date_import' => $lastDate ? new \DateTime($lastDate) : null,
        ]);
    }

    // ============================================================
    // FAISCEAUX HERTZIENS
    // ============================================================
    #[Route('/superuser/fh', name: 'superuser_fh')]
    public function fh(EntityManagerInterface $em, Request $request): Response
    {
        $search      = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        
        // Récupérer la dernière date d'import
        $lastDate = $this->getLastImportDate($em);
        
        $sites = $this->getSitesByType($em, 'FH', $search, $classFilter);
        $stats = $this->getStatsByType($em, 'FH');

        return $this->render('dashboard/_transmission_table.html.twig', [
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'FH',
            'titre'       => 'Faisceaux Hertziens',
            'search'      => $search,
            'classFilter' => $classFilter,
            'derniere_date_import' => $lastDate ? new \DateTime($lastDate) : null,
        ]);
    }

    // ============================================================
    // BACKBONE PARTAGÉ
    // ============================================================
    #[Route('/superuser/backbone', name: 'superuser_backbone')]
    public function backbone(EntityManagerInterface $em, Request $request): Response
    {
        $search      = $request->query->get('search', '');
        $classFilter = $request->query->get('classification', '');
        
        // Récupérer la dernière date d'import
        $lastDate = $this->getLastImportDate($em);
        
        $sites = $this->getSitesByType($em, 'BACKBONE', $search, $classFilter);
        $stats = $this->getStatsByType($em, 'BACKBONE');

        return $this->render('dashboard/_transmission_table.html.twig', [
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'BACKBONE',
            'titre'       => 'Backbone Partagé',
            'search'      => $search,
            'classFilter' => $classFilter,
            'derniere_date_import' => $lastDate ? new \DateTime($lastDate) : null,
        ]);
    }
}