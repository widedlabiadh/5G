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
            'enode_name' => 'eNode Name',
            'classification' => 'Classification',
            'type_liaison' => 'Type Liaison',
            'max_trafic' => 'Trafic Max (Mbps)',
            'seuil_critique' => 'Seuil Critique (%)',
            'nombre_occurrences' => 'Nb Occurrences',
            'updated_at' => 'Date MAJ',
        ];
        
        if (!empty($kpis)) {
            $columns = array_intersect_key($allColumns, array_flip($kpis));
            $columns = array_merge(['enode_name' => 'eNode Name'], $columns);
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
        
        // Écrire les en-têtes (Version corrigée)
        $col = 'A';
        foreach ($columns as $key => $label) {
            $sheet->setCellValue($col . '1', $label);
            $sheet->getColumnDimension($col)->setWidth(20);
            $col++;
        }
        
        // Déterminer la dernière lettre de colonne
        $lastColLetter = $col === 'A' ? 'A' : chr(ord($col) - 1);
        $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);
        
        // Écrire les données (Version corrigée)
        $row = 2;
        foreach ($data as $rowData) {
            $col = 'A';
            foreach (array_keys($columns) as $key) {
                $value = match($key) {
                    'enode_name' => $rowData['enode_name'] ?? '—',
                    'classification' => strtoupper($rowData['classification'] ?? '—'),
                    'type_liaison' => strtoupper($rowData['type_liaison'] ?? '—'),
                    'max_trafic' => isset($rowData['max_trafic']) ? round(floatval($rowData['max_trafic']), 2) : '—',
                    'seuil_critique' => isset($rowData['seuil_critique']) ? round(floatval($rowData['seuil_critique']), 2) . '%' : '—',
                    'nombre_occurrences' => $rowData['nombre_occurrences'] ?? 0,
                    'updated_at' => isset($rowData['updated_at']) ? (new \DateTime($rowData['updated_at']))->format('d/m/Y H:i') : '—',
                    default => '—',
                };
                $sheet->setCellValue($col . $row, $value);
                $col++;
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
}