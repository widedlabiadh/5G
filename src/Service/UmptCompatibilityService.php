<?php

namespace App\Service;

class UmptCompatibilityService
{
    private const SWAP_REQUIRED = ['UMPTB1', 'UMPTB2', 'UMPTE1'];
    private const COMPATIBLE = ['UMPTE2', 'UMPTGA2'];

    public function normalizeCardType(mixed $cardType): string
    {
        return strtoupper(str_replace([' ', '-', '_'], '', trim((string) $cardType)));
    }

    public function canonicalCardType(mixed $cardType): string
    {
        return match ($this->normalizeCardType($cardType)) {
            'UMPTB1' => 'UMPTb1',
            'UMPTB2' => 'UMPTb2',
            'UMPTE1' => 'UMPTe1',
            'UMPTE2' => 'UMPTe2',
            'UMPTGA2' => 'UMPTga2',
            default => trim((string) $cardType),
        };
    }

    public function isSwapRequired(mixed $cardType): bool
    {
        return in_array($this->normalizeCardType($cardType), self::SWAP_REQUIRED, true);
    }

    public function isKnownCompatible(mixed $cardType): bool
    {
        return in_array($this->normalizeCardType($cardType), self::COMPATIBLE, true);
    }

    public function commentFor(mixed $cardType): string
    {
        $normalized = $this->normalizeCardType($cardType);
        $canonical = $this->canonicalCardType($cardType);

        if (in_array($normalized, self::SWAP_REQUIRED, true)) {
            return sprintf('Swap carte nécessaire (%s -> UMPTe2/UMPTga2)', $canonical);
        }

        if (in_array($normalized, self::COMPATIBLE, true)) {
            return 'Carte compatible - Pas de swap requis';
        }

        return 'Type UMPT non renseigné - vérification SuperUser requise';
    }
}
