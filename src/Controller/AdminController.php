<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private UserManager $userManager;

    public function __construct(UserManager $userManager)
    {
        $this->userManager = $userManager;
    }

    #[Route('/dashboard', name: 'admin_dashboard')]
    #[Route('/dashboard/home', name: 'admin_dashboard_home')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        return $this->renderAdminDashboard($em->getConnection(), 'ALL');
    }

    #[Route('/dashboard/sites/{service}', name: 'admin_dashboard_sites', defaults: ['service' => 'ALL'])]
    public function dashboardSites(EntityManagerInterface $em, string $service = 'ALL'): Response
    {
        return $this->renderAdminDashboard($em->getConnection(), $service);
    }

    #[Route('/dashboard/export/statistics.pdf', name: 'admin_export_statistics_pdf')]
    public function exportStatisticsPdf(EntityManagerInterface $em): Response
    {
        $data = $this->buildDashboardData($em->getConnection(), 'ALL');
        $html = $this->renderView('admin/statistics_pdf.html.twig', $data);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="admin_statistics_' . date('Ymd_His') . '.pdf"',
            ]
        );
    }

    private function renderAdminDashboard(Connection $conn, string $service): Response
    {
        return $this->render('admin/index.html.twig', $this->buildDashboardData($conn, $service));
    }

    private function buildDashboardData(Connection $conn, string $service): array
    {
        $selectedService = $this->normalizeService($service);
        $lastAnalyseDate = $conn->fetchOne('SELECT MAX(date_analyse) FROM analyse_resultat') ?: null;

        $emptyDistribution = ['FO' => 0, 'FH' => 0, 'SHARED' => 0];
        if (!$lastAnalyseDate) {
            return [
                'user' => $this->getUser(),
                'selectedService' => $selectedService,
                'lastAnalyseDate' => null,
                'totalSites' => 0,
                'criticalSites' => 0,
                'criticalPercentage' => 0,
                'avgTraffic' => 0,
                'maxTraffic' => 0,
                'activeServices' => 0,
                'serviceDistribution' => $emptyDistribution,
                'classificationStats' => [],
                'classificationLabels' => [],
                'sites' => [],
            ];
        }

        [$filterSql, $params] = $this->buildServiceFilter($selectedService);
        $params['date'] = $lastAnalyseDate;
        $criticalSql = $this->criticalThresholdSql();

        $totalSites = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM analyse_resultat WHERE date_analyse = :date' . $filterSql,
            $params
        );
        $criticalSites = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM analyse_resultat WHERE date_analyse = :date' . $filterSql . ' AND nombre_occurrences >= ' . $criticalSql,
            $params
        );
        $avgTraffic = (float) $conn->fetchOne(
            'SELECT COALESCE(AVG(max_trafic), 0) FROM analyse_resultat WHERE date_analyse = :date' . $filterSql,
            $params
        );
        $maxTraffic = (float) $conn->fetchOne(
            'SELECT COALESCE(MAX(max_trafic), 0) FROM analyse_resultat WHERE date_analyse = :date' . $filterSql,
            $params
        );

        $serviceDistribution = $this->fetchServiceDistribution($conn, $lastAnalyseDate);
        $classificationStats = $this->fetchClassificationStats($conn, $lastAnalyseDate, $filterSql, $params);
        $sites = $this->fetchLatestSites($conn, $lastAnalyseDate, $filterSql, $params);

        return [
            'user' => $this->getUser(),
            'selectedService' => $selectedService,
            'lastAnalyseDate' => new \DateTime((string) $lastAnalyseDate),
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
            'avgTraffic' => round($avgTraffic, 2),
            'maxTraffic' => round($maxTraffic, 2),
            'activeServices' => count(array_filter($serviceDistribution, static fn (int $count): bool => $count > 0)),
            'serviceDistribution' => $serviceDistribution,
            'classificationStats' => $classificationStats,
            'classificationLabels' => array_keys($classificationStats),
            'sites' => $sites,
        ];
    }

    private function normalizeService(?string $service): string
    {
        $service = strtoupper(trim((string) $service));

        return in_array($service, ['FO', 'FH', 'SHARED'], true) ? $service : 'ALL';
    }

    private function buildServiceFilter(string $service): array
    {
        return match ($service) {
            'FO' => [' AND UPPER(type_trans) LIKE :serviceType', ['serviceType' => 'FO%']],
            'FH' => [' AND UPPER(type_trans) LIKE :serviceType', ['serviceType' => 'FH%']],
            'SHARED' => [' AND (UPPER(type_trans) = :backbone OR UPPER(type_trans) LIKE :bhPattern)', ['backbone' => 'BACKBONE', 'bhPattern' => 'BH%']],
            default => ['', []],
        };
    }

    private function criticalThresholdSql(): string
    {
        return 'CEIL(50.0 * COALESCE(NULLIF(duree_jours, 0), 7) / 7.0)';
    }

    private function fetchServiceDistribution(Connection $conn, string $lastAnalyseDate): array
    {
        $row = $conn->fetchAssociative(
            "SELECT
                SUM(CASE WHEN UPPER(type_trans) LIKE 'FO%' THEN 1 ELSE 0 END) AS fo,
                SUM(CASE WHEN UPPER(type_trans) LIKE 'FH%' THEN 1 ELSE 0 END) AS fh,
                SUM(CASE WHEN UPPER(type_trans) = 'BACKBONE' OR UPPER(type_trans) LIKE 'BH%' THEN 1 ELSE 0 END) AS shared
             FROM analyse_resultat
             WHERE date_analyse = :date",
            ['date' => $lastAnalyseDate]
        ) ?: [];

        return [
            'FO' => (int) ($row['fo'] ?? 0),
            'FH' => (int) ($row['fh'] ?? 0),
            'SHARED' => (int) ($row['shared'] ?? 0),
        ];
    }

    private function fetchClassificationStats(Connection $conn, string $lastAnalyseDate, string $filterSql, array $params): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT
                CASE
                    WHEN UPPER(classification) IN (\'ONLY_FDD\', \'FDD\', \'-\') THEN \'ONLY_FDD\'
                    WHEN classification IS NULL OR TRIM(classification) = \'\' THEN \'NON_CLASSE\'
                    ELSE UPPER(classification)
                END AS classification_label,
                COUNT(*) AS total,
                SUM(CASE WHEN nombre_occurrences >= ' . $this->criticalThresholdSql() . ' THEN 1 ELSE 0 END) AS critical
             FROM analyse_resultat
             WHERE date_analyse = :date' . $filterSql . '
             GROUP BY classification_label
             ORDER BY classification_label',
            $params
        );

        $stats = [];
        foreach (['TF', 'COTRANS', 'NO_COTRANS', 'NON_TDD', 'ONLY_FDD'] as $label) {
            $stats[$label] = ['total' => 0, 'critical' => 0];
        }

        foreach ($rows as $row) {
            $label = (string) ($row['classification_label'] ?? 'NON_CLASSE');
            $stats[$label] = [
                'total' => (int) ($row['total'] ?? 0),
                'critical' => (int) ($row['critical'] ?? 0),
            ];
        }

        return array_filter($stats, static fn (array $row): bool => $row['total'] > 0 || $row['critical'] > 0);
    }

    private function fetchLatestSites(Connection $conn, string $lastAnalyseDate, string $filterSql, array $params): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT site, type_trans, classification, max_trafic, nombre_occurrences, duree_jours, date_max
             FROM analyse_resultat
             WHERE date_analyse = :date' . $filterSql . '
             ORDER BY date_max DESC NULLS LAST, id DESC
             LIMIT 8',
            $params
        );

        return array_map(function (array $row): array {
            $occurrences = (int) ($row['nombre_occurrences'] ?? 0);
            $dureeJours = max(1, (int) ($row['duree_jours'] ?? 7));

            return [
                'siteName' => $this->cleanSiteName((string) ($row['site'] ?? '')),
                'serviceName' => $this->serviceNameFromType($row['type_trans'] ?? null),
                'classification' => $row['classification'] ?? '-',
                'typeTrans' => $row['type_trans'] ?? '-',
                'maxTrafic' => (float) ($row['max_trafic'] ?? 0),
                'nombreOccurrences' => $occurrences,
                'isCritical' => $occurrences >= (int) ceil((50 * $dureeJours) / 7),
                'dateMax' => $row['date_max'] ? new \DateTime((string) $row['date_max']) : null,
            ];
        }, $rows);
    }

    private function serviceNameFromType(?string $typeTrans): string
    {
        $type = strtoupper(trim((string) $typeTrans));

        if (str_starts_with($type, 'FO')) {
            return 'FO';
        }
        if (str_starts_with($type, 'FH')) {
            return 'FH';
        }
        if ($type === 'BACKBONE' || str_starts_with($type, 'BH')) {
            return 'SHARED';
        }

        return 'NON_DEFINI';
    }

    private function cleanSiteName(string $site): string
    {
        $site = strtoupper(trim($site));
        $site = preg_replace('/_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$/i', '', $site) ?: $site;
        $site = preg_replace('/_(BH|BO|TR|OO|OR|TT)(?:_[A-Z0-9]+)*$/i', '', $site) ?: $site;

        return $site ?: '-';
    }

    #[Route('/users', name: 'admin_users')]
    public function users(UserRepository $userRepository): Response
    {
        return $this->render('admin/users.html.twig', [
            'user'  => $this->getUser(),
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/users/new', name: 'admin_user_new')]
    public function newUser(Request $request, EntityManagerInterface $em): Response
    {
        $newUser = new User();
        $form = $this->createForm(UserType::class, $newUser, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $this->userManager->createUser($newUser, $plainPassword);
            $this->addFlash('success', '✅ Utilisateur créé avec succès !');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user'    => $this->getUser(),
            'form'    => $form->createView(),
            'isNew'   => true,
            'title'   => 'Ajouter un utilisateur',
        ]);
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit')]
    public function editUser(User $targetUser, Request $request): Response
    {
        $form = $this->createForm(UserType::class, $targetUser, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userManager->updateUser($targetUser);
            $this->addFlash('success', '✅ Utilisateur modifié avec succès !');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user'       => $this->getUser(),
            'form'       => $form->createView(),
            'isEdit'     => true,
            'title'      => 'Modifier ' . $targetUser->getFullName(),
            'targetUser' => $targetUser,
        ]);
    }

    #[Route('/users/{id}/toggle', name: 'admin_user_toggle')]
    public function toggleUser(User $targetUser): Response
    {
        if ($this->userManager->toggleUser($targetUser, $this->getUser())) {
            $status = $targetUser->isActive() ? 'activé' : 'désactivé';
            $this->addFlash('success', "✅ Compte {$status} avec succès !");
        } else {
            $this->addFlash('error', '❌ Vous ne pouvez pas désactiver votre propre compte !');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $targetUser, Request $request): Response
    {
        $token = $request->request->get('_token');
        if ($this->userManager->deleteUser($targetUser, $this->getUser(), $token, 'delete' . $targetUser->getId())) {
            $this->addFlash('success', "✅ Utilisateur '{$targetUser->getFullName()}' supprimé !");
        } else {
            $this->addFlash('error', '❌ Suppression impossible (compte personnel ou token invalide).');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function resetPassword(User $targetUser, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reset' . $targetUser->getId(), $token)) {
            $this->addFlash('error', '❌ Token invalide.');
            return $this->redirectToRoute('admin_users');
        }
        $newPassword = $request->request->get('new_password');
        if ($this->userManager->resetPassword($targetUser, $newPassword)) {
            $this->addFlash('success', "✅ Mot de passe réinitialisé !");
        } else {
            $this->addFlash('error', '❌ Mot de passe trop court (min 6 caractères).');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/settings', name: 'admin_settings')]
    public function settings(Request $request): Response
    {
        $user = $this->getUser();
        $session = $request->getSession();
        $existingSettings = $session->get('site_settings', [
            'site_name' => 'NetWON',
            'items_per_page' => 10,
            'maintenance_mode' => false
        ]);

        $form = $this->createFormBuilder()
            ->add('site_name', TextType::class, ['label' => 'Nom du site', 'required' => false, 'data' => $existingSettings['site_name']])
            ->add('items_per_page', IntegerType::class, ['label' => 'Éléments par page', 'required' => false, 'data' => $existingSettings['items_per_page']])
            ->add('maintenance_mode', CheckboxType::class, ['label' => 'Mode maintenance', 'required' => false, 'data' => $existingSettings['maintenance_mode']])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('site_settings', $form->getData());
            $this->addFlash('success', '✅ Paramètres sauvegardés avec succès !');
            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}
