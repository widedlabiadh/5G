<?php

namespace App\Controller;

use App\Repository\SiteDataRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

#[Route('/export')]
class ExportController extends AbstractController
{
    #[Route('/trafic', name: 'export_trafic_form')]
    public function form(SiteDataRepository $repo): Response
    {
        try {
            $sites = $repo->findAllEnodeNames();
            
            return $this->render('export/trafic.html.twig', [
                'user' => $this->getUser(),
                'sites' => $sites,
                'canExportAll' => true,
            ]);
        } catch (\Exception $e) {
            return new Response('Erreur: ' . $e->getMessage(), 500);
        }
    }
    
    #[Route('/trafic/generate', name: 'export_trafic_generate', methods: ['POST'])]
    public function generate(Request $request, SiteDataRepository $repo): StreamedResponse
    {
        $sitesInput = $request->request->get('sites', '');
        $sitesList = array_map('trim', explode(',', $sitesInput));
        $sitesList = array_filter($sitesList);
        
        $dateRange = $request->request->get('date_range', 'all');
        $dateValue = $request->request->get('date_value', '');
        $kpis = $request->request->all('kpis') ?? [];
        
        $data = $repo->findForExport($sitesList, $dateRange, $dateValue);
        
        return $this->generateExcel($data, $kpis);
    }
    
    private function generateExcel(array $data, array $kpis): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export Trafic');
        
        $allColumns = [
            'enode_name' => 'Site',
            'classification' => 'Classification',
            'type_liaison' => 'Type Liaison',
            'etat' => 'Etat',
            'statut' => 'Decision',
            'capacite_tdd_mbps' => 'Capacite TDD (Mbps)',
            'capacite_fdd_mbps' => 'Capacite FDD (Mbps)',
            'max_trafic' => 'Trafic Max (Mbps)',
            'seuil_critique' => 'Seuil Critique (%)',
            'nombre_occurrences' => 'Nb Occurrences',
            'nombre_occurrences_tdd' => 'Nb Occurrences TDD',
            'nombre_occurrences_fdd' => 'Nb Occurrences FDD',
            'updated_at' => 'Date MAJ',
        ];
        
        if (!empty($kpis)) {
            $columns = array_intersect_key($allColumns, array_flip($kpis));
            $columns = array_merge(['enode_name' => 'Site'], $columns);
        } else {
            $columns = $allColumns;
        }
        
        // Style pour l'en-tête
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF6600']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ];
        
        // Écrire les en-têtes
        foreach (array_values($columns) as $index => $label) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($col . '1', $label);
            $sheet->getColumnDimension($col)->setWidth(20);
        }
        
        // Déterminer la dernière lettre de colonne
        $lastColLetter = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);
        
        // Écrire les données
        $row = 2;
        foreach ($data as $rowData) {
            foreach (array_keys($columns) as $index => $key) {
                $col = Coordinate::stringFromColumnIndex($index + 1);
                $value = match($key) {
                    'enode_name' => strtoupper((string) ($rowData['site_export'] ?? $this->cleanSiteName((string) ($rowData['enode_name'] ?? '')))),
                    'classification' => strtoupper($rowData['classification'] ?? '—'),
                    'type_liaison' => strtoupper($rowData['type_liaison'] ?? '—'),
                    'etat' => $this->normalizeEtat((string) ($rowData['etat'] ?? 'OK')),
                    'statut' => $this->normalizeDecision((string) ($rowData['statut'] ?? 'OK')),
                    'capacite_tdd_mbps' => isset($rowData['capacite_tdd_mbps']) ? $this->formatDecimalNoRounding($rowData['capacite_tdd_mbps']) : '—',
                    'capacite_fdd_mbps' => isset($rowData['capacite_fdd_mbps']) ? $this->formatDecimalNoRounding($rowData['capacite_fdd_mbps']) : '—',
                    'max_trafic' => isset($rowData['max_trafic']) ? round(floatval($rowData['max_trafic']), 2) : '—',
                    'seuil_critique' => isset($rowData['seuil_critique']) ? round(floatval($rowData['seuil_critique']), 2) . '%' : '—',
                    'nombre_occurrences' => $rowData['nombre_occurrences'] ?? 0,
                    'nombre_occurrences_tdd' => $rowData['nombre_occurrences_tdd'] ?? 0,
                    'nombre_occurrences_fdd' => $rowData['nombre_occurrences_fdd'] ?? 0,
                    'updated_at' => isset($rowData['updated_at']) ? (new \DateTime($rowData['updated_at']))->format('d/m/Y H:i') : '—',
                    default => '—',
                };
                $sheet->setCellValue($col . $row, $value);
            }
            $row++;
        }
        
        // Figer la première ligne
        $sheet->freezePane('A2');
        
        // Auto-filter
        $sheet->setAutoFilter('A1:' . $lastColLetter . '1');
        
        $filename = 'export_trafic_' . date('Ymd_His') . '.xlsx';
        
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });
        
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        
        return $response;
    }

    private function cleanSiteName(string $site): string
    {
        $site = trim($site);
        if ($site === '') {
            return '—';
        }

        return strtoupper(preg_replace('/_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$/i', '', $site) ?: $site);
    }

    private function formatDecimalNoRounding(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '0';
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return $value;
        }

        if (stripos($value, 'e') !== false) {
            $value = sprintf('%.14F', (float) $value);
        }

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return str_replace('.', ',', $value);
    }

    private function normalizeEtat(string $etat): string
    {
        return match (strtoupper(trim($etat))) {
            'CONGESTIONNE', 'CONGESTION' => 'CONGESTION',
            'RISQUE_DE_CONGESTION' => 'RISQUE DE CONGESTION',
            'BRIDE', 'BRIDAGE' => 'BRIDAGE',
            default => 'OK',
        };
    }

    private function normalizeDecision(string $decision): string
    {
        $decision = strtoupper(trim($decision));
        $decision = str_replace('_', ' ', $decision);

        if (preg_match('/^CRITIQUE(?:\s*\((TDD|FDD|TDD\/FDD)\))?$/', $decision, $matches)) {
            return isset($matches[1]) ? 'CRITIQUE (' . $matches[1] . ')' : 'CRITIQUE';
        }

        if (preg_match('/^(SOUS OBSERVATION|SURVEILLANCE)(?:\s*\((TDD|FDD|TDD\/FDD)\))?$/', $decision, $matches)) {
            return isset($matches[2]) ? 'SOUS OBSERVATION (' . $matches[2] . ')' : 'SOUS OBSERVATION';
        }

        return 'OK';
    }
}
