<?php
// src/Twig/SiteCleanerExtension.php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class SiteCleanerExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('clean_site', [$this, 'cleanSiteName']),
        ];
    }

    /**
     * Supprime les suffixes courants d'un nom de site :
     * - _LM, _TC, _TD, _TDD, _tf, _BO_, _BH_, etc.
     * Exemple: "ARI_0003_LM" -> "ARI_0003"
     */
    public function cleanSiteName(?string $name): string
    {
        if (empty($name)) {
            return '—';
        }

        // Supprime tout ce qui suit un suffixe connu (insensible à la casse)
        $cleaned = preg_replace('/_(LM|TC|TD|TDD|tf|BO_.*|BH_.*)$/i', '', $name);

        return $cleaned ?: $name;
    }
}