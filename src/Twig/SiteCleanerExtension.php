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
     * Supprime les suffixes techniques courants d'un nom de site.
     * Exemple: "ARI_0140_00" -> "ARI_0140"
     */
    public function cleanSiteName(?string $name): string
    {
        if (empty($name)) {
            return 'â€”';
        }

        $cleaned = trim($name);

        do {
            $previous = $cleaned;
            $cleaned = preg_replace('/_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$/i', '', $cleaned);
            $cleaned = preg_replace('/_(BH|BO|TR|OO|OR|TT)(?:_[A-Z0-9]+)*$/i', '', $cleaned);
            $cleaned = trim((string) $cleaned);
        } while ($cleaned !== $previous);

        return $cleaned ?: $name;
    }
}
