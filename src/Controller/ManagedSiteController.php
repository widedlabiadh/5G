<?php

namespace App\Controller;

use App\Entity\ManagedSite;
use App\Form\ManagedSiteType;
use App\Repository\AnalyseResultatRepository;
use App\Repository\ManagedSiteRepository;
use App\Service\CapacityUtilizationService;
use App\Service\SiteImportService;
use App\Service\SiteStatusDecisionService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/superuser/sites')]
#[IsGranted('ROLE_SUPERUSER')]
class ManagedSiteController extends AbstractController
{
    public function __construct(
        private readonly CapacityUtilizationService $capacityUtilizationService,
        private readonly SiteStatusDecisionService $siteStatusDecisionService,
        private readonly SiteImportService $siteImportService,
    ) {
    }

    private const EXCEL_HEADERS = [
        'Site',
        'Classification',
        'Type',
        'Max TDD',
        'Max FDD',
        'Trafic Max',
        'Capacite TDD (Mbps)',
        'Capacite FDD (Mbps)',
        'Taux Utilisation',
        'Taux Utilisation TDD',
        'Taux Utilisation FDD',
        'Occurrences',
        'Occurrence TDD',
        'Occurrence FDD',
        'Statut',
        'Etat',
        'Date Max',
        'Latitude',
        'Longitude',
        'Notes',
    ];

    #[Route('', name: 'superuser_sites_index', methods: ['GET'])]
    public function index(Request $request, AnalyseResultatRepository $analyseRepository, ManagedSiteRepository $repository, EntityManagerInterface $em): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $analysisSites = $analyseRepository->findLatestSitesSnapshot();
        $managedByName = $repository->findByNamesInsensitiveIndexed(array_map(static fn (array $row): string => (string) ($row['site'] ?? ''), $analysisSites));
        $importedCapacities = $this->findImportedCapacities($em);
        $managedOnly = [];
        foreach ($repository->findAllOrdered() as $managed) {
            if (!$managed instanceof ManagedSite) {
                continue;
            }
            $key = mb_strtolower(trim((string) $managed->getName()));
            if ($key !== '' && !isset($managedByName[$key]) && !array_filter($analysisSites, static fn (array $row): bool => mb_strtolower(trim((string) ($row['site'] ?? ''))) === $key)) {
                $managedOnly[] = $managed;
            }
        }

        $sites = [];
        foreach ($analysisSites as $row) {
            $siteName = trim((string) ($row['site'] ?? ''));
            if ($siteName === '') {
                continue;
            }

            $key = mb_strtolower($siteName);
            $managed = $managedByName[$key] ?? null;

            $resolved = $this->buildSiteDisplayRow($siteName, $row, $managed, $importedCapacities);
            $resolved += [
                'name' => $siteName,
                'analysis' => $row,
                'managed' => $managed,
                'id' => $managed?->getId(),
                'latitude' => $managed?->getLatitude() ?? ($row['latitude'] ?? null),
                'longitude' => $managed?->getLongitude() ?? ($row['longitude'] ?? null),
                'description' => $managed?->getDescription(),
                'updatedAt' => $managed?->getUpdatedAt(),
                'sourceStatus' => $managed ? 'superuser' : 'analyse',
            ];

            $sites[] = $resolved;
        }

        foreach ($managedOnly as $managed) {
            $resolved = $this->buildSiteDisplayRow((string) $managed->getName(), null, $managed, $importedCapacities);
            $sites[] = $resolved + [
                'name' => (string) $managed->getName(),
                'analysis' => null,
                'managed' => $managed,
                'id' => $managed->getId(),
                'latitude' => $managed->getLatitude(),
                'longitude' => $managed->getLongitude(),
                'description' => $managed->getDescription(),
                'updatedAt' => $managed->getUpdatedAt(),
                'sourceStatus' => 'superuser',
            ];
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $sites = array_values(array_filter($sites, static function (array $site) use ($needle): bool {
                return str_contains(mb_strtolower((string) $site['name']), $needle)
                    || str_contains(mb_strtolower((string) ($site['typeTrans'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($site['classification'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($site['description'] ?? '')), $needle);
            }));
        }

        $completeSites = array_filter($sites, static function (array $site): bool {
            return $site['maxTrafic'] !== null
                && !empty($site['typeTrans'])
                && !empty($site['classification'])
                && $site['latitude'] !== null
                && $site['longitude'] !== null;
        });

        $missingType = count(array_filter($sites, static fn (array $site) => empty($site['typeTrans'])));
        $missingClassification = count(array_filter($sites, static fn (array $site) => empty($site['classification'])));
        $missingCoords = count(array_filter($sites, static fn (array $site) => $site['latitude'] === null || $site['longitude'] === null));

        $stats = [
            'total' => count($sites),
            'complete' => count($completeSites),
            'missingType' => $missingType,
            'missingClassification' => $missingClassification,
            'missingCoords' => $missingCoords,
        ];

        return $this->render('superuser/sites/index.html.twig', [
            'sites' => $sites,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    #[Route('/export-template', name: 'superuser_sites_export_template', methods: ['GET'])]
    public function exportTemplate(AnalyseResultatRepository $analyseRepository, ManagedSiteRepository $repository): StreamedResponse
    {
        $rows = $this->buildExcelRows($analyseRepository, $repository);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sites');

        foreach (self::EXCEL_HEADERS as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $header);
            $sheet->getColumnDimension($column)->setWidth($index === 0 ? 28 : 20);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count(self::EXCEL_HEADERS));
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F97316']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . '1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue($column . $rowNumber, $value);
            }
            $rowNumber++;
        }

        if ($rowNumber === 2) {
            $sheet->setCellValue('A2', 'NOM_SITE_EXEMPLE');
        }

        $filename = 'template_sites_' . date('Ymd_His') . '.xlsx';

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    #[Route('/import-template', name: 'superuser_sites_import_template', methods: ['POST'])]
    public function importTemplate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_sites_template', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('superuser_sites_index');
        }

        $file = $request->files->get('sites_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Veuillez selectionner un fichier Excel.');

            return $this->redirectToRoute('superuser_sites_index');
        }

        try {
            $result = $this->siteImportService->importFromExcel($file);

            $msg = match ($result['intent']) {
                'update_gps' => 'Mise à jour des coordonnées GPS/Classification terminée.',
                default => 'Import terminé — données synchronisées dans le tableau de transmission.',
            };

            $this->addFlash('success', sprintf('%s (%d ajouté(s), %d modifié(s), %d ignoré(s)).', $msg, $result['created'], $result['updated'], $result['ignored']));
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Import impossible: ' . $exception->getMessage());
        }

        return $this->redirectToRoute('superuser_sites_index');
    }

    #[Route('/new', name: 'superuser_sites_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, AnalyseResultatRepository $analyseRepository): Response
    {
        $site = new ManagedSite();
        $referenceSite = trim((string) $request->query->get('site', ''));
        $referenceSnapshot = null;
        if ($referenceSite !== '') {
            $referenceSnapshot = $analyseRepository->findLatestKnownSnapshotBySite($referenceSite);
            $site->setName($referenceSite);
            $this->applyReferenceSnapshot($site, $referenceSnapshot, true);
        }

        $form = $this->createForm(ManagedSiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $referenceSnapshot = $analyseRepository->findLatestKnownSnapshotBySite((string) $site->getName());
            $this->applyReferenceSnapshot($site, $referenceSnapshot, false);

            $em->persist($site);
            $em->flush();
            $this->syncToAnalyseResultat($em, $site);

            $this->addFlash('success', 'Site cree avec succes.');

            return $this->redirectToRoute('superuser_sites_index');
        }

        return $this->render('superuser/sites/form.html.twig', [
            'form' => $form->createView(),
            'site' => $site,
            'isEdit' => false,
            'pageTitle' => 'Ajouter un site',
            'pageSubtitle' => 'Creer une fiche de site en respectant le format du tableau de supervision.',
            'referenceSite' => $referenceSnapshot,
            'referenceSites' => $analyseRepository->findReferenceSites(),
            'editableName' => $referenceSite === '',
        ]);
    }

    #[Route('/{id}/edit', name: 'superuser_sites_edit', methods: ['GET', 'POST'])]
    public function edit(ManagedSite $site, Request $request, EntityManagerInterface $em, AnalyseResultatRepository $analyseRepository): Response
    {
        $form = $this->createForm(ManagedSiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->syncToAnalyseResultat($em, $site);

            $this->addFlash('success', 'Site mis a jour avec succes.');

            return $this->redirectToRoute('superuser_sites_index');
        }

        return $this->render('superuser/sites/form.html.twig', [
            'form' => $form->createView(),
            'site' => $site,
            'isEdit' => true,
            'pageTitle' => 'Completer le site',
            'pageSubtitle' => 'Renseigner les champs manquants ou corriger les valeurs du site.',
            'referenceSite' => $analyseRepository->findLatestKnownSnapshotBySite((string) $site->getName()),
            'referenceSites' => [],
            'editableName' => false,
        ]);
    }

    #[Route('/reference/{site}', name: 'superuser_sites_reference', requirements: ['site' => '.+'], methods: ['GET'])]
    public function reference(string $site, AnalyseResultatRepository $analyseRepository): JsonResponse
    {
        $snapshot = $analyseRepository->findLatestKnownSnapshotBySite($site);

        return $this->json([
            'found' => $snapshot !== null,
            'site' => $snapshot['site'] ?? $site,
            'classification' => $snapshot['classification'] ?? null,
            'typeTrans' => $snapshot['type_trans'] ?? null,
            'maxTrafic' => $snapshot['max_trafic'] ?? null,
            'latitude' => $snapshot['latitude'] ?? null,
            'longitude' => $snapshot['longitude'] ?? null,
            'dateAnalyse' => $snapshot['date_analyse'] ?? null,
        ]);
    }

    #[Route('/site/{site}', name: 'superuser_sites_edit_by_site', requirements: ['site' => '.+'], methods: ['GET', 'POST'])]
    public function editBySite(string $site, Request $request, EntityManagerInterface $em, AnalyseResultatRepository $analyseRepository, ManagedSiteRepository $repository): Response
    {
        $siteName = trim($site);
        $managedSite = $repository->findOneByName($siteName) ?? new ManagedSite();

        if ($managedSite->getId() === null) {
            $managedSite->setName($siteName);
        }

        $snapshot = $analyseRepository->findLatestKnownSnapshotBySite($siteName);
        if ($snapshot) {
            if ($managedSite->getMaxTrafic() === null && isset($snapshot['max_trafic'])) {
                $managedSite->setMaxTrafic((string) $snapshot['max_trafic']);
            }
            if (!$managedSite->getTypeTrans() && isset($snapshot['type_trans'])) {
                $managedSite->setTypeTrans($snapshot['type_trans']);
            }
            if (!$managedSite->getClassification() && isset($snapshot['classification'])) {
                $managedSite->setClassification($snapshot['classification']);
            }
            if ($managedSite->getLatitude() === null && isset($snapshot['latitude'])) {
                $managedSite->setLatitude((float) $snapshot['latitude']);
            }
            if ($managedSite->getLongitude() === null && isset($snapshot['longitude'])) {
                $managedSite->setLongitude((float) $snapshot['longitude']);
            }
        }

        $form = $this->createForm(ManagedSiteType::class, $managedSite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($managedSite->getId() === null) {
                $em->persist($managedSite);
            }
            $em->flush();
            $this->syncToAnalyseResultat($em, $managedSite);

            $this->addFlash('success', sprintf('Fiche du site "%s" enregistree.', $siteName));

            return $this->redirectToRoute('superuser_sites_index');
        }

        return $this->render('superuser/sites/form.html.twig', [
            'form' => $form->createView(),
            'site' => $managedSite,
            'isEdit' => $managedSite->getId() !== null,
            'pageTitle' => 'Completer le site',
            'pageSubtitle' => 'Les champs sont pre-remplis depuis la derniere analyse, puis sauvegardes dans managed_site.',
            'referenceSite' => $snapshot,
            'referenceSites' => [],
            'editableName' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'superuser_sites_delete', methods: ['POST'])]
    public function delete(ManagedSite $site, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_site_' . $site->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('superuser_sites_index');
        }

        $name = (string) $site->getName();
        $suffix = '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$';

        $em->remove($site);
        $em->flush();

        // Supprimer de analyse_resultat les enregistrements sans données réelles
        // (placeholders créés par le CRUD, total_measures=0 et max_trafic=0).
        // Les enregistrements issus d'analyses Python réelles (total_measures>0) sont conservés.
        $em->getConnection()->executeStatement(
            'DELETE FROM analyse_resultat
             WHERE (UPPER(site) = UPPER(:site)
                 OR UPPER(site_fdd) = UPPER(:site)
                 OR UPPER(site_tdd) = UPPER(:site)
                 OR UPPER(REGEXP_REPLACE(site, :suffix, \'\', \'i\')) = UPPER(:site))
               AND (total_measures IS NULL OR total_measures = 0)
               AND (max_trafic IS NULL OR max_trafic = 0)',
            ['site' => $name, 'suffix' => $suffix]
        );

        $this->addFlash('success', sprintf('Site "%s" supprime avec succes.', $name));

        return $this->redirectToRoute('superuser_sites_index');
    }

    /**
     * @return array<string, float>
     */
    private function findImportedCapacities(EntityManagerInterface $em): array
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT site, capacite_mbps
             FROM capacite_site
             WHERE capacite_mbps > 0
             ORDER BY date_mise_ajour DESC, id DESC'
        );

        $capacities = [];
        foreach ($rows as $row) {
            $site = strtoupper(trim((string) ($row['site'] ?? '')));
            $capacity = (float) ($row['capacite_mbps'] ?? 0);
            if ($site === '' || $capacity <= 0) {
                continue;
            }

            foreach ([$site, strtoupper($this->cleanSiteName($site))] as $key) {
                if ($key !== '' && !isset($capacities[$key])) {
                    $capacities[$key] = $capacity;
                }
            }
        }

        return $capacities;
    }

    /**
     * @param array<string, mixed>|null $analysis
     * @param array<string, float> $importedCapacities
     * @return array<string, mixed>
     */
    private function buildSiteDisplayRow(string $siteName, ?array $analysis, ?ManagedSite $managed, array $importedCapacities): array
    {
        $classification = (string) ($managed?->getClassification() ?? ($analysis['classification'] ?? ''));
        $typeTrans = $managed?->getTypeTrans() ?? ($analysis['type_trans'] ?? null);
        $maxTrafic = $managed?->getMaxTrafic() ?? ($analysis['max_trafic'] ?? null);
        $maxTdd = $managed?->getMaxTdd() ?? ($analysis['max_trafic_tdd'] ?? 0);
        $maxFdd = $managed?->getMaxFdd() ?? ($analysis['max_trafic_fdd'] ?? 0);
        $capaciteTdd = (float) ($managed?->getCapaciteTddMbps() ?? 0);
        $capaciteFdd = (float) ($managed?->getCapaciteFddMbps() ?? 0);

        $importedCapacity = $this->findImportedCapacityForSite($siteName, $importedCapacities);
        if ($importedCapacity > 0) {
            if ($this->capacityUtilizationService->usesFddCapacityOnly($classification) || strtoupper((string) $typeTrans) === 'FH') {
                $capaciteFdd = $importedCapacity;
                $capaciteTdd = 0.0;
            } else {
                $capaciteTdd = $importedCapacity;
                $capaciteFdd = 0.0;
            }
        } elseif (($capaciteTdd + $capaciteFdd) <= 0 && (float) ($analysis['capacite_mbps'] ?? 0) > 0) {
            if ($this->capacityUtilizationService->usesFddCapacityOnly($classification) || strtoupper((string) $typeTrans) === 'FH') {
                $capaciteFdd = (float) $analysis['capacite_mbps'];
            } else {
                $capaciteTdd = (float) $analysis['capacite_mbps'];
            }
        }

        $occurrences = $managed ? $managed->getNombreOccurrences() : (int) ($analysis['nombre_occurrences'] ?? 0);
        $occurrencesTdd = $managed ? $managed->getNombreOccurrencesTdd() : (int) ($analysis['nombre_occurrences_tdd'] ?? 0);
        $occurrencesFdd = $managed ? $managed->getNombreOccurrencesFdd() : (int) ($analysis['nombre_occurrences_fdd'] ?? 0);
        $dureeJours = (int) ($analysis['duree_jours'] ?? 7);
        $effectiveCapacity = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
            $classification,
            $capaciteTdd,
            $capaciteFdd,
            (float) ($analysis['capacite_mbps'] ?? 0)
        );

        $clsNorm = strtoupper(preg_replace('/\s+/u', '', $classification));
        $isCotrans = str_contains($clsNorm, 'COTRANS');

        $rates = $this->capacityUtilizationService->computeUtilizationRates(
            (float) ($maxTrafic ?? 0),
            (float) $maxTdd,
            (float) $maxFdd,
            $classification,
            $capaciteTdd,
            $capaciteFdd,
            $effectiveCapacity
        );

        // Taux global : manuel si défini et non-COTRANS, sinon calculé (null pour COTRANS)
        if (!$isCotrans && $managed?->getTauxUtilisation() !== null && $managed->getTauxUtilisation() !== '') {
            $tauxUtilisation = $this->siteStatusDecisionService->extractUtilizationRate(['taux_utilisation' => $managed->getTauxUtilisation()]);
        } else {
            $tauxUtilisation = $rates['taux_utilisation'];
        }
        $tauxUtilisationTdd = $rates['taux_utilisation_tdd'];
        $tauxUtilisationFdd = $rates['taux_utilisation_fdd'];

        $decision = $this->siteStatusDecisionService->decide([
            'nombre_occurrences' => $occurrences,
            'nombre_occurrences_tdd' => $occurrencesTdd,
            'nombre_occurrences_fdd' => $occurrencesFdd,
            'taux_utilisation' => $tauxUtilisation,
            'taux_utilisation_tdd' => $tauxUtilisationTdd,
            'taux_utilisation_fdd' => $tauxUtilisationFdd,
            'classification' => $classification,
            'capacite_mbps' => $effectiveCapacity,
            'type_trans' => $typeTrans,
            'duree_jours' => $dureeJours,
        ], $dureeJours);

        return [
            'classification' => $classification,
            'typeTrans' => $typeTrans,
            'maxTrafic' => $maxTrafic,
            'maxTdd' => $maxTdd,
            'maxFdd' => $maxFdd,
            'capaciteTddMbps' => $capaciteTdd,
            'capaciteFddMbps' => $capaciteFdd,
            'tauxUtilisation' => $tauxUtilisation,
            'tauxUtilisationTdd' => $tauxUtilisationTdd,
            'tauxUtilisationFdd' => $tauxUtilisationFdd,
            'nombreOccurrences' => $occurrences,
            'nombreOccurrencesTdd' => $occurrencesTdd,
            'nombreOccurrencesFdd' => $occurrencesFdd,
            'statut' => $decision['label'],
            'etat' => $decision['etat'],
            'dateMax' => $managed?->getDateMax() ?? ($analysis['date_max'] ?? null),
        ];
    }

    /**
     * @param array<string, float> $importedCapacities
     */
    private function findImportedCapacityForSite(string $siteName, array $importedCapacities): float
    {
        foreach ([strtoupper($siteName), strtoupper($this->cleanSiteName($siteName))] as $key) {
            if ($key !== '' && isset($importedCapacities[$key])) {
                return $importedCapacities[$key];
            }
        }

        return 0.0;
    }

    private function cleanSiteName(string $siteName): string
    {
        return preg_replace('/_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$/i', '', trim($siteName)) ?: trim($siteName);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function buildExcelRows(AnalyseResultatRepository $analyseRepository, ManagedSiteRepository $repository): array
    {
        $analysisSites = $analyseRepository->findLatestSitesSnapshot();
        $managedByName = $repository->findByNamesInsensitiveIndexed(array_map(static fn (array $row): string => (string) ($row['site'] ?? ''), $analysisSites));
        $seen = [];
        $rows = [];

        foreach ($analysisSites as $analysis) {
            $siteName = trim((string) ($analysis['site'] ?? ''));
            if ($siteName === '') {
                continue;
            }

            $key = mb_strtolower($siteName);
            $seen[$key] = true;
            $rows[] = $this->buildExcelRow($siteName, $analysis, $managedByName[$key] ?? null);
        }

        foreach ($repository->findAllOrdered() as $managed) {
            if (!$managed instanceof ManagedSite) {
                continue;
            }

            $siteName = trim((string) $managed->getName());
            $key = mb_strtolower($siteName);
            if ($siteName === '' || isset($seen[$key])) {
                continue;
            }

            $rows[] = $this->buildExcelRow($siteName, null, $managed);
        }

        return $rows;
    }

    /**
     * @return array<int, mixed>
     */
    private function buildExcelRow(string $siteName, ?array $analysis, ?ManagedSite $managed): array
    {
        $maxTrafic = $managed?->getMaxTrafic() ?? ($analysis['max_trafic'] ?? null);
        $capaciteTdd = (float) ($managed?->getCapaciteTddMbps() ?? 0);
        $capaciteFdd = (float) ($managed?->getCapaciteFddMbps() ?? 0);
        $classification = (string) ($managed?->getClassification() ?? ($analysis['classification'] ?? ''));
        $effectiveCapacity = $this->capacityUtilizationService->resolveEffectiveCapacityMbps(
            $classification,
            $capaciteTdd,
            $capaciteFdd,
            $capaciteTdd + $capaciteFdd > 0 ? $capaciteTdd + $capaciteFdd : (float) ($analysis['capacite_mbps'] ?? 0)
        );

        $maxTraficValue = (float) ($maxTrafic ?? 0);
        $clsNorm = strtoupper(preg_replace('/\s+/u', '', $classification));
        $isCotrans = str_contains($clsNorm, 'COTRANS');
        $maxTddVal = (float) ($managed?->getMaxTdd() ?? ($analysis['max_trafic_tdd'] ?? 0));
        $maxFddVal = (float) ($managed?->getMaxFdd() ?? ($analysis['max_trafic_fdd'] ?? 0));

        $rates = $this->capacityUtilizationService->computeUtilizationRates(
            $maxTraficValue,
            $maxTddVal,
            $maxFddVal,
            $classification,
            $capaciteTdd,
            $capaciteFdd,
            $effectiveCapacity
        );

        if (!$isCotrans && $managed?->getTauxUtilisation() !== null && $managed->getTauxUtilisation() !== '') {
            $tauxUtilisation = $this->siteStatusDecisionService->extractUtilizationRate(['taux_utilisation' => $managed->getTauxUtilisation()]);
        } else {
            $tauxUtilisation = $rates['taux_utilisation'];
        }
        $tauxUtilisationTdd = $rates['taux_utilisation_tdd'];
        $tauxUtilisationFdd = $rates['taux_utilisation_fdd'];

        $occurrences = $managed ? $managed->getNombreOccurrences() : (int) ($analysis['nombre_occurrences'] ?? 0);
        $occurrencesTdd = $managed ? $managed->getNombreOccurrencesTdd() : (int) ($analysis['nombre_occurrences_tdd'] ?? 0);
        $occurrencesFdd = $managed ? $managed->getNombreOccurrencesFdd() : (int) ($analysis['nombre_occurrences_fdd'] ?? 0);
        $dureeJours = (int) ($analysis['duree_jours'] ?? 7);

        $decision = $this->siteStatusDecisionService->decide([
            'nombre_occurrences' => $occurrences,
            'nombre_occurrences_tdd' => $occurrencesTdd,
            'nombre_occurrences_fdd' => $occurrencesFdd,
            'taux_utilisation' => $tauxUtilisation,
            'taux_utilisation_tdd' => $tauxUtilisationTdd,
            'taux_utilisation_fdd' => $tauxUtilisationFdd,
            'classification' => $classification,
            'capacite_mbps' => $effectiveCapacity,
            'type_trans' => $managed?->getTypeTrans() ?? ($analysis['type_trans'] ?? ''),
            'duree_jours' => $dureeJours,
        ], $dureeJours);

        $formatTaux = static fn (?float $v): string => $v !== null ? number_format($v, 2, ',', '') . '%' : '';

        return [
            strtoupper($siteName),
            $managed?->getClassification() ?? ($analysis['classification'] ?? ''),
            $managed?->getTypeTrans() ?? ($analysis['type_trans'] ?? ''),
            $managed?->getMaxTdd(),
            $managed?->getMaxFdd(),
            $maxTrafic,
            $capaciteTdd > 0 ? $capaciteTdd : null,
            $capaciteFdd > 0 ? $capaciteFdd : null,
            $formatTaux($tauxUtilisation),
            $formatTaux($tauxUtilisationTdd),
            $formatTaux($tauxUtilisationFdd),
            $occurrences,
            $occurrencesTdd,
            $occurrencesFdd,
            $decision['label'],
            $decision['etat'],
            $this->formatExcelDate($managed?->getDateMax() ?? ($analysis['date_max'] ?? null)),
            $managed?->getLatitude() ?? ($analysis['latitude'] ?? null),
            $managed?->getLongitude() ?? ($analysis['longitude'] ?? null),
            $managed?->getDescription(),
        ];
    }

    private function formatExcelDate(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d H:i');
        }

        if ($date === null || $date === '') {
            return '';
        }

        try {
            return (new \DateTime((string) $date))->format('Y-m-d H:i');
        } catch (\Exception) {
            return (string) $date;
        }
    }

    private function syncToAnalyseResultat(EntityManagerInterface $em, ManagedSite $site): void
    {
        $conn = $em->getConnection();
        $siteName = (string) $site->getName();
        $suffix = '_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$';

        $exists = (bool) $conn->fetchOne(
            'SELECT 1 FROM analyse_resultat
             WHERE UPPER(site) = UPPER(:site)
                OR UPPER(site_fdd) = UPPER(:site)
                OR UPPER(site_tdd) = UPPER(:site)
                OR UPPER(REGEXP_REPLACE(site, :suffix, \'\', \'i\')) = UPPER(:site)',
            ['site' => $siteName, 'suffix' => $suffix]
        );

        $capTdd = (float) ($site->getCapaciteTddMbps() ?? 0);
        $capFdd = (float) ($site->getCapaciteFddMbps() ?? 0);
        $capacite = $capTdd + $capFdd > 0 ? max($capTdd, $capFdd) : null;

        if (!$exists) {
            $lastDate = $conn->fetchOne(
                'SELECT date_analyse FROM analyse_resultat ORDER BY date_analyse DESC LIMIT 1'
            ) ?: date('Y-m-d H:i:s');

            $conn->executeStatement(
                'INSERT INTO analyse_resultat
                    (site, classification, type_trans, max_trafic, seuil_critique,
                     nombre_occurrences, nombre_occurrences_tdd, nombre_occurrences_fdd,
                     total_measures, date_analyse, duree_jours,
                     latitude, longitude, capacite_mbps, drop_cong_kpi)
                 VALUES
                    (:site, :classification, :type_trans, 0, 0,
                     0, 0, 0,
                     0, :date_analyse, 7,
                     :latitude, :longitude, :capacite_mbps, false)',
                [
                    'site'          => $siteName,
                    'classification' => $site->getClassification() ?? '',
                    'type_trans'    => $site->getTypeTrans(),
                    'date_analyse'  => $lastDate,
                    'latitude'      => $site->getLatitude(),
                    'longitude'     => $site->getLongitude(),
                    'capacite_mbps' => $capacite,
                ]
            );

            return;
        }

        $fields = [];
        $params = ['site' => $siteName, 'suffix' => $suffix];

        if ($site->getClassification()) {
            $fields[] = 'classification = :classification';
            $params['classification'] = $site->getClassification();
        }
        if ($site->getTypeTrans() !== null && $site->getTypeTrans() !== '') {
            $fields[] = 'type_trans = :type_trans';
            $params['type_trans'] = $site->getTypeTrans();
        }
        if ($site->getLatitude() !== null) {
            $fields[] = 'latitude = :latitude';
            $params['latitude'] = $site->getLatitude();
        }
        if ($site->getLongitude() !== null) {
            $fields[] = 'longitude = :longitude';
            $params['longitude'] = $site->getLongitude();
        }
        if ($capacite !== null) {
            $fields[] = 'capacite_mbps = :capacite';
            $params['capacite'] = $capacite;
        }

        if ($fields === []) {
            return;
        }

        $conn->executeStatement(
            'UPDATE analyse_resultat SET ' . implode(', ', $fields) . '
             WHERE UPPER(site) = UPPER(:site)
                OR UPPER(site_fdd) = UPPER(:site)
                OR UPPER(site_tdd) = UPPER(:site)
                OR UPPER(REGEXP_REPLACE(site, :suffix, \'\', \'i\')) = UPPER(:site)',
            $params
        );
    }

    private function applyReferenceSnapshot(ManagedSite $site, ?array $snapshot, bool $forceCoordinates): void
    {
        if (!$snapshot) {
            return;
        }

        if ($site->getMaxTrafic() === null && isset($snapshot['max_trafic'])) {
            $site->setMaxTrafic((string) $snapshot['max_trafic']);
        }
        if (!$site->getTypeTrans() && isset($snapshot['type_trans'])) {
            $site->setTypeTrans($snapshot['type_trans']);
        }
        if (!$site->getClassification() && isset($snapshot['classification'])) {
            $site->setClassification($snapshot['classification']);
        }

        $latitude = $snapshot['latitude'] ?? null;
        $longitude = $snapshot['longitude'] ?? null;
        if ($latitude === null || $longitude === null || (float) $latitude === 0.0 || (float) $longitude === 0.0) {
            return;
        }

        if ($forceCoordinates || $site->getLatitude() === null) {
            $site->setLatitude((float) $latitude);
        }
        if ($forceCoordinates || $site->getLongitude() === null) {
            $site->setLongitude((float) $longitude);
        }
    }
}
