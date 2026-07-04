<?php

namespace App\Service;

use App\Entity\UmptSite;
use App\Repository\UmptSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UmptImportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UmptSiteRepository $repository,
        private UmptCompatibilityService $compatibility
    ) {
    }

    public function import(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        [$headerRow, $siteColumn, $cardColumn] = $this->detectColumns($sheet, $highestColumnIndex, min($highestRow, 10));

        $created = 0;
        $updated = 0;
        $ignored = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $site = UmptSiteRepository::normalizeSite($sheet->getCell(Coordinate::stringFromColumnIndex($siteColumn) . $row)->getFormattedValue());
            $cardTypeRaw = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($cardColumn) . $row)->getFormattedValue());

            if ($site === '' || $cardTypeRaw === '') {
                $ignored++;
                continue;
            }

            $cardType = $this->compatibility->canonicalCardType($cardTypeRaw);
            $umptSite = $this->repository->findOneByNormalizedSite($site);

            if (!$umptSite) {
                $umptSite = new UmptSite();
                $umptSite->setSite($site);
                $this->em->persist($umptSite);
                $created++;
            } else {
                $updated++;
            }

            $umptSite->setCardType($cardType);
            $umptSite->setSwapRequired($this->compatibility->isSwapRequired($cardType));
            $umptSite->setSwapComment($this->compatibility->commentFor($cardType));
            $umptSite->setSourceFile($file->getClientOriginalName());
            $umptSite->setUpdatedAt(new \DateTime());
        }

        $this->em->flush();

        return [
            'created' => $created,
            'updated' => $updated,
            'ignored' => $ignored,
        ];
    }

    private function detectColumns($sheet, int $highestColumnIndex, int $maxRows): array
    {
        for ($row = 1; $row <= $maxRows; $row++) {
            $siteColumn = null;
            $cardColumn = null;

            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $header = $this->normalizeHeader($sheet->getCell(Coordinate::stringFromColumnIndex($column) . $row)->getFormattedValue());

                if ($siteColumn === null && (str_contains($header, 'site') || str_contains($header, 'bts') || str_contains($header, 'nodeb'))) {
                    $siteColumn = $column;
                }

                if ($cardColumn === null && (str_contains($header, 'umpt') || str_contains($header, 'carte') || str_contains($header, 'card'))) {
                    $cardColumn = $column;
                }
            }

            if ($siteColumn !== null && $cardColumn !== null) {
                return [$row, $siteColumn, $cardColumn];
            }
        }

        return [1, 1, 2];
    }

    private function normalizeHeader(mixed $value): string
    {
        $header = strtolower(trim((string) $value));
        $header = str_replace([' ', '-', '_'], '', $header);

        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;
    }
}
