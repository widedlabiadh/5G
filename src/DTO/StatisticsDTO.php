<?php

namespace App\DTO;

class StatisticsDTO
{
    public int $totalSites;
    public int $totalOccurrences;
    public int $foSites;
    public int $fhSites;
    public int $backboneSites;
    // Ajoutez tous les champs nécessaires

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->totalSites = $data['total_sites'] ?? 0;
        $dto->totalOccurrences = $data['total_occurrences'] ?? 0;
        $dto->foSites = $data['fo'] ?? 0;
        $dto->fhSites = $data['fh'] ?? 0;
        $dto->backboneSites = $data['backbone'] ?? 0;
        return $dto;
    }
}