<?php
// src/Controller/DashboardController.php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AnalyseResultat;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    // ============================================================
    // HELPERS PRIVÉS
    // ============================================================

    private function getStatsFromDatabase(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();

        $totalSites       = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat');
        $sitesTF          = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = ?', ['TF']);
        $sitesCOTRANS     = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = ?', ['COTRANS']);
        $sitesNOCOTRANS   = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = ?', ['NO_COTRANS']);
        $sitesNON_TDD     = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE classification = ?', ['NON_TDD']);
        $totalOccurrences = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat');
        $fo               = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = ?', ['FO']);
        $fh               = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = ?', ['FH']);
        $backbone         = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = ?', ['BACKBONE']);
        $sansType         = $conn->fetchOne('SELECT COALESCE(COUNT(*), 0) FROM analyse_resultat WHERE type_trans = ?', ['NON_DEFINI']);
        $foCongestions        = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = ?', ['FO']);
        $fhCongestions        = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = ?', ['FH']);
        $backboneCongestions  = $conn->fetchOne('SELECT COALESCE(SUM(nombre_occurrences), 0) FROM analyse_resultat WHERE type_trans = ?', ['BACKBONE']);
        $maxTraficFO          = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = ?', ['FO']);
        $maxTraficFH          = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = ?', ['FH']);
        $maxTraficBackbone    = $conn->fetchOne('SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE type_trans = ?', ['BACKBONE']);

        return [
            'total_sites'          => (int)$totalSites,
            'sites_tf'             => (int)$sitesTF,
            'sites_cotrans'        => (int)$sitesCOTRANS,
            'sites_nocotrans'      => (int)$sitesNOCOTRANS,
            'sites_nontdd'         => (int)$sitesNON_TDD,
            'total_occurrences'    => (int)$totalOccurrences,
            'fo'                   => (int)$fo,
            'fh'                   => (int)$fh,
            'backbone'             => (int)$backbone,
            'sans_type'            => (int)$sansType,
            'fo_congestions'       => (int)$foCongestions,
            'fh_congestions'       => (int)$fhCongestions,
            'backbone_congestions' => (int)$backboneCongestions,
            'max_trafic_fo'        => (float)$maxTraficFO,
            'max_trafic_fh'        => (float)$maxTraficFH,
            'max_trafic_backbone'  => (float)$maxTraficBackbone,
        ];
    }

    private function getSitesCritiques(EntityManagerInterface $em, int $limit = 20): array
    {
        $conn    = $em->getConnection();
        $results = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat
             WHERE nombre_occurrences > 50
             ORDER BY nombre_occurrences DESC
             LIMIT :limit',
            ['limit' => $limit]
        );

        $sites = [];
        foreach ($results as $row) {
            $site = new AnalyseResultat();
            $site->setSite($row['site']);
            $site->setClassification($row['classification']);
            $site->setTypeTrans($row['type_trans']);
            $site->setMaxTrafic((float)$row['max_trafic']);
            $site->setSeuilCritique((float)$row['seuil_critique']);
            $site->setNombreOccurrences((int)$row['nombre_occurrences']);
            $site->setTotalMeasures((int)$row['total_measures']);
            $site->setDateAnalyse(new \DateTime($row['date_analyse']));
            $sites[] = $site;
        }

        return $sites;
    }

    private function getDerniereAnalyse(EntityManagerInterface $em): ?AnalyseResultat
    {
        $conn = $em->getConnection();
        $row  = $conn->fetchAssociative(
            'SELECT * FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
        );

        if (!$row) return null;

        $site = new AnalyseResultat();
        $site->setSite($row['site']);
        $site->setClassification($row['classification']);
        $site->setTypeTrans($row['type_trans']);
        $site->setMaxTrafic((float)$row['max_trafic']);
        $site->setSeuilCritique((float)$row['seuil_critique']);
        $site->setNombreOccurrences((int)$row['nombre_occurrences']);
        $site->setTotalMeasures((int)$row['total_measures']);
        $site->setDateAnalyse(new \DateTime($row['date_analyse']));

        return $site;
    }

    private function getUserStats(UserRepository $userRepository): array
    {
        $users         = $userRepository->findAll();
        $totalUsers    = count($users);
        $activeUsers   = count(array_filter($users, fn($u) => $u->isActive()));
        $inactiveUsers = $totalUsers - $activeUsers;
        $roles         = array_unique(array_filter(array_map(fn($u) => $u->getRole(), $users)));
        $roleCount     = count($roles);

        return [
            'users'         => $users,
            'totalUsers'    => $totalUsers,
            'activeUsers'   => $activeUsers,
            'inactiveUsers' => $inactiveUsers,
            'roleCount'     => $roleCount,
        ];
    }

    // ============================================================
    // ROUTE PRINCIPALE — redirige selon le rôle
    // ============================================================

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return match ($user->getRole()) {
            'ROLE_ADMIN'      => $this->redirectToRoute('admin_dashboard'),
            'ROLE_SUPERUSER'  => $this->redirectToRoute('superuser_dashboard'),
            'ROLE_CONTROLEUR' => $this->redirectToRoute('controleur_dashboard'),
            'ROLE_INGENIEUR'  => $this->redirectToRoute('ingenieur_dashboard'),
            default           => $this->redirectToRoute('app_login'),
        };
    }

    // ============================================================
    // ADMIN — SUPPRIMÉ pour éviter le doublon avec AdminController
    // ============================================================

    // ⚠️ Les routes admin_dashboard et admin_users sont gérées
    //    uniquement dans AdminController.php — ne pas les dupliquer ici.

    // ============================================================
    // SUPERUSER
    // ============================================================

    #[Route('/superuser/dashboard', name: 'superuser_dashboard')]
    public function superuserDashboard(EntityManagerInterface $em): Response
    {
        $stats           = $this->getStatsFromDatabase($em);
        $sitesCritiques  = $this->getSitesCritiques($em, 20);
        $derniereAnalyse = $this->getDerniereAnalyse($em);

        return $this->render('dashboard/superuser.html.twig', [
            'user'             => $this->getUser(),
            'stats'            => $stats,
            'sites_critiques'  => $sitesCritiques,
            'derniere_analyse' => $derniereAnalyse,
        ]);
    }

    #[Route('/superuser/import', name: 'superuser_import')]
    public function superuserImport(): Response
    {
        return $this->redirectToRoute('import_excel');
    }

    #[Route('/superuser/workflows', name: 'superuser_workflows')]
    public function superuserWorkflows(): Response
    {
        return $this->render('dashboard/superuser_workflows.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/superuser/historique', name: 'superuser_historique')]
    public function superuserHistorique(EntityManagerInterface $em): Response
    {
        $conn     = $em->getConnection();
        $analyses = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 500'
        );

        return $this->render('dashboard/superuser_historique.html.twig', [
            'user'     => $this->getUser(),
            'analyses' => $analyses,
        ]);
    }

    #[Route('/superuser/rapports', name: 'superuser_rapports')]
    public function superuserRapports(): Response
    {
        return $this->render('dashboard/superuser_rapports.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    // ============================================================
    // CONTRÔLEUR
    // ============================================================

    #[Route('/controleur/dashboard', name: 'controleur_dashboard')]
    public function controleurDashboard(EntityManagerInterface $em): Response
    {
        $stats           = $this->getStatsFromDatabase($em);
        $sitesCritiques  = $this->getSitesCritiques($em, 10);
        $derniereAnalyse = $this->getDerniereAnalyse($em);

        return $this->render('dashboard/controleur.html.twig', [
            'user'             => $this->getUser(),
            'stats'            => $stats,
            'sites_critiques'  => $sitesCritiques,
            'derniere_analyse' => $derniereAnalyse,
        ]);
    }

    #[Route('/controleur/sites', name: 'controleur_sites')]
    public function controleurSites(EntityManagerInterface $em): Response
    {
        $conn  = $em->getConnection();
        $sites = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat ORDER BY max_trafic DESC'
        );

        return $this->render('dashboard/controleur_sites.html.twig', [
            'user'  => $this->getUser(),
            'sites' => $sites,
        ]);
    }

    #[Route('/controleur/tickets', name: 'controleur_tickets')]
    public function controleurTickets(): Response
    {
        return $this->render('dashboard/controleur_tickets.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/controleur/workflows', name: 'controleur_workflows')]
    public function controleurWorkflows(): Response
    {
        return $this->render('dashboard/controleur_workflows.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/controleur/rapports', name: 'controleur_rapports')]
    public function controleurRapports(): Response
    {
        return $this->render('dashboard/controleur_rapports.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    // ============================================================
    // INGÉNIEUR
    // ============================================================

    #[Route('/ingenieur/dashboard', name: 'ingenieur_dashboard')]
    public function ingenieurDashboard(EntityManagerInterface $em): Response
    {
        $stats           = $this->getStatsFromDatabase($em);
        $sitesCritiques  = $this->getSitesCritiques($em, 20);
        $derniereAnalyse = $this->getDerniereAnalyse($em);

        return $this->render('dashboard/ingenieur.html.twig', [
            'user'             => $this->getUser(),
            'stats'            => $stats,
            'sites_critiques'  => $sitesCritiques,
            'derniere_analyse' => $derniereAnalyse,
        ]);
    }

    #[Route('/ingenieur/sites', name: 'ingenieur_sites')]
    public function ingenieurSites(EntityManagerInterface $em): Response
    {
        $conn  = $em->getConnection();
        $sites = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat ORDER BY max_trafic DESC'
        );

        return $this->render('dashboard/ingenieur_sites.html.twig', [
            'user'  => $this->getUser(),
            'sites' => $sites,
        ]);
    }

    #[Route('/ingenieur/alertes', name: 'ingenieur_alertes')]
    public function ingenieurAlertes(EntityManagerInterface $em): Response
    {
        $conn    = $em->getConnection();
        $alertes = $conn->fetchAllAssociative(
            'SELECT * FROM analyse_resultat WHERE nombre_occurrences > 30 ORDER BY nombre_occurrences DESC'
        );

        return $this->render('dashboard/ingenieur_alertes.html.twig', [
            'user'    => $this->getUser(),
            'alertes' => $alertes,
        ]);
    }

    #[Route('/ingenieur/swap/{id}', name: 'ingenieur_swap')]
    public function ingenieurSwap(int $id, EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();
        $site = $conn->fetchAssociative('SELECT * FROM analyse_resultat WHERE id = ?', [$id]);

        return $this->render('dashboard/ingenieur_swap.html.twig', [
            'user'   => $this->getUser(),
            'site'   => $site,
            'siteId' => $id,
        ]);
    }

    #[Route('/ingenieur/historique/{id}', name: 'ingenieur_historique')]
    public function ingenieurHistorique(int $id, EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();
        $site = $conn->fetchAssociative('SELECT * FROM analyse_resultat WHERE id = ?', [$id]);

        return $this->render('dashboard/ingenieur_historique.html.twig', [
            'user'   => $this->getUser(),
            'site'   => $site,
            'siteId' => $id,
        ]);
    }

    // ============================================================
    // WORKFLOW
    // ============================================================

    #[Route('/workflow/create', name: 'workflow_create', methods: ['POST'])]
    public function createWorkflow(Request $request): Response
    {
        // TODO: persister en BDD avec Doctrine
        $this->addFlash('success', 'Workflow créé avec succès !');
        return $this->redirectToRoute('superuser_dashboard');
    }

    // ============================================================
    // DÉCONNEXION
    // ============================================================

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Intercepted by the firewall.');
    }
}