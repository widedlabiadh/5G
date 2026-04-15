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
    // HELPER : récupère les sites par type de transmission
    // ============================================================
    private function getSitesByType(EntityManagerInterface $em, string $type): array
    {
        $conn = $em->getConnection();

        return $conn->fetchAllAssociative(
            'SELECT
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
             WHERE UPPER(type_trans) = :type
             ORDER BY nombre_occurrences DESC, max_trafic DESC',
            ['type' => strtoupper($type)]
        );
    }

    // ============================================================
    // HELPER : stats par type
    // ============================================================
    private function getStatsByType(EntityManagerInterface $em, string $type): array
    {
        $conn = $em->getConnection();
        $t    = strtoupper($type);

        return [
            'total'           => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ?', [$t]),
            'total_tf'        => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ?', [$t, 'TF']),
            'total_cotrans'   => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ?', [$t, 'COTRANS']),
            'total_nocotrans' => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ?', [$t, 'NO_COTRANS']),
            'total_nontdd'    => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND classification = ?', [$t, 'NON_TDD']),
            'total_occ'       => (int)$conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences),0) FROM analyse_resultat WHERE UPPER(type_trans) = ?', [$t]),
            'max_trafic'      => (float)$conn->fetchOne('SELECT COALESCE(MAX(max_trafic),0) FROM analyse_resultat WHERE UPPER(type_trans) = ?', [$t]),
            'sites_critiques' => (int)$conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE UPPER(type_trans) = ? AND nombre_occurrences > 50', [$t]),
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

        $conn  = $em->getConnection();
        $qb    = 'SELECT id, site, classification, type_trans, site_tdd, site_fdd,
                         max_trafic, somme_fdd_tdd, seuil_critique, nombre_occurrences,
                         total_measures, date_max, date_analyse
                  FROM analyse_resultat
                  WHERE UPPER(type_trans) = :type';
        $params = ['type' => 'FO'];

        if ($search) {
            $qb .= " AND (LOWER(site) LIKE :search OR LOWER(site_tdd) LIKE :search OR LOWER(site_fdd) LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        if ($classFilter) {
            $qb .= " AND classification = :class";
            $params['class'] = $classFilter;
        }

        $qb   .= ' ORDER BY nombre_occurrences DESC, max_trafic DESC';
        $sites = $conn->fetchAllAssociative($qb, $params);
        $stats = $this->getStatsByType($em, 'FO');

        return $this->render('dashboard/fo.html.twig', [
            'user'         => $this->getUser(),
            'sites'        => $sites,
            'stats'        => $stats,
            'type'         => 'FO',
            'titre'        => 'Fibre Optique',
            'search'       => $search,
            'classFilter'  => $classFilter,
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

        $conn   = $em->getConnection();
        $qb     = 'SELECT id, site, classification, type_trans, site_tdd, site_fdd,
                          max_trafic, somme_fdd_tdd, seuil_critique, nombre_occurrences,
                          total_measures, date_max, date_analyse
                   FROM analyse_resultat
                   WHERE UPPER(type_trans) = :type';
        $params = ['type' => 'FH'];

        if ($search) {
            $qb .= " AND (LOWER(site) LIKE :search OR LOWER(site_tdd) LIKE :search OR LOWER(site_fdd) LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        if ($classFilter) {
            $qb .= " AND classification = :class";
            $params['class'] = $classFilter;
        }

        $qb   .= ' ORDER BY nombre_occurrences DESC, max_trafic DESC';
        $sites = $conn->fetchAllAssociative($qb, $params);
        $stats = $this->getStatsByType($em, 'FH');

        return $this->render('dashboard/fh.html.twig', [
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'FH',
            'titre'       => 'Faisceaux Hertziens',
            'search'      => $search,
            'classFilter' => $classFilter,
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

        $conn   = $em->getConnection();
        $qb     = 'SELECT id, site, classification, type_trans, site_tdd, site_fdd,
                          max_trafic, somme_fdd_tdd, seuil_critique, nombre_occurrences,
                          total_measures, date_max, date_analyse
                   FROM analyse_resultat
                   WHERE UPPER(type_trans) = :type';
        $params = ['type' => 'BACKBONE'];

        if ($search) {
            $qb .= " AND (LOWER(site) LIKE :search OR LOWER(site_tdd) LIKE :search OR LOWER(site_fdd) LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        if ($classFilter) {
            $qb .= " AND classification = :class";
            $params['class'] = $classFilter;
        }

        $qb   .= ' ORDER BY nombre_occurrences DESC, max_trafic DESC';
        $sites = $conn->fetchAllAssociative($qb, $params);
        $stats = $this->getStatsByType($em, 'BACKBONE');

        return $this->render('dashboard/backbone.html.twig', [
            'user'        => $this->getUser(),
            'sites'       => $sites,
            'stats'       => $stats,
            'type'        => 'BACKBONE',
            'titre'       => 'Backbone Partagé',
            'search'      => $search,
            'classFilter' => $classFilter,
        ]);
    }
}