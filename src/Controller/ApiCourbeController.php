<?php
// src/Controller/ApiCourbeController.php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiCourbeController extends AbstractController
{
    #[Route('/api/sites/liste', name: 'api_sites_liste', methods: ['GET'])]
    public function listeSites(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $lastDate = $this->getLastAnalyseDate($conn);

        if ($lastDate !== null) {
            $sites = $conn->fetchFirstColumn(
                'SELECT site FROM analyse_resultat WHERE date_analyse = :date ORDER BY site',
                ['date' => $lastDate]
            );

            return $this->json($sites);
        }

        $sites = $conn->fetchFirstColumn('SELECT DISTINCT site FROM trafic_historique ORDER BY site');

        return $this->json($sites);
    }

    #[Route('/api/site/{baseSite}/types', name: 'api_site_types', methods: ['GET'])]
    public function getSiteTypes(string $baseSite, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $analyse = $this->getLatestAnalyseForSite($conn, $baseSite);

        if ($analyse === null) {
            return $this->json($this->buildFallbackTypes($conn, $baseSite));
        }

        $classification = strtoupper((string) $analyse['classification']);
        $siteTf = null;
        $siteLm = null;
        $siteTc = null;
        $isPlain = false;

        if ($classification === 'TF') {
            $siteTf = $this->findTrafficSite($conn, $baseSite, ['TF']);
        } elseif (in_array($classification, ['COTRANS', 'NO_COTRANS'], true)) {
            $siteLm = $this->existingTrafficSite($conn, $analyse['site_fdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['LM', 'LMB', 'LM2', 'FDD']);
            $siteTc = $this->existingTrafficSite($conn, $analyse['site_tdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['TC', 'TD', 'TDD']);
        } elseif ($classification === 'FDD') {
            $siteLm = $this->existingTrafficSite($conn, $analyse['site_fdd'] ?? null)
                ?? $this->findTrafficSite($conn, $baseSite, ['LM', 'LMB', 'LM2', 'FDD']);
        } else {
            $isPlain = true;
        }

        if ($isPlain) {
            $sitePlain = $this->existingTrafficSite($conn, $baseSite) ?? $this->findTrafficSite($conn, $baseSite, []);
        } else {
            $sitePlain = null;
        }

        return $this->json([
            'classification' => $classification,
            'hasTF' => $siteTf !== null,
            'hasLM' => $siteLm !== null,
            'hasTC' => $siteTc !== null,
            'isPlain' => $isPlain,
            'siteTF' => $siteTf,
            'siteLM' => $siteLm,
            'siteTC' => $siteTc,
            'sitePlain' => $sitePlain,
            'defaultMode' => $this->getDefaultMode($classification, $siteTf, $siteLm, $siteTc, $isPlain),
        ]);
    }

    #[Route('/api/site/{baseSite}/trafic', name: 'api_site_trafic', methods: ['GET'])]
    public function getTrafic(string $baseSite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $type = $request->query->get('type');
        $siteName = $request->query->get('siteName');

        if (!$siteName) {
            $types = json_decode($this->getSiteTypes($baseSite, $em)->getContent(), true);
            $siteName = match ($type) {
                'TF' => $types['siteTF'] ?? null,
                'LM' => $types['siteLM'] ?? null,
                'TC' => $types['siteTC'] ?? null,
                default => $types['sitePlain'] ?? $baseSite,
            };
        }

        return $this->json($this->fetchTrafficRows($em->getConnection(), (string) $siteName, $request));
    }

    #[Route('/api/site/{baseSite}/both-trafic', name: 'api_site_both_trafic', methods: ['GET'])]
    public function getBothTrafic(string $baseSite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $types = json_decode($this->getSiteTypes($baseSite, $em)->getContent(), true);
        $siteLm = $request->query->get('siteLM') ?: ($types['siteLM'] ?? null);
        $siteTc = $request->query->get('siteTC') ?: ($types['siteTC'] ?? null);

        return $this->json([
            'LM' => [
                'label' => $siteLm ?: $baseSite . '_LM',
                'data' => $siteLm ? $this->fetchTrafficRows($conn, $siteLm, $request) : [],
            ],
            'TC' => [
                'label' => $siteTc ?: $baseSite . '_TC',
                'data' => $siteTc ? $this->fetchTrafficRows($conn, $siteTc, $request) : [],
            ],
        ]);
    }

    private function getLastAnalyseDate(Connection $conn): ?string
    {
        $date = $conn->fetchOne('SELECT MAX(date_analyse) FROM analyse_resultat');

        return $date ? (string) $date : null;
    }

    private function getLatestAnalyseForSite(Connection $conn, string $site): ?array
    {
        $lastDate = $this->getLastAnalyseDate($conn);
        if ($lastDate === null) {
            return null;
        }

        $row = $conn->fetchAssociative(
            'SELECT site, classification, site_fdd, site_tdd
             FROM analyse_resultat
             WHERE date_analyse = :date AND UPPER(site) = UPPER(:site)
             LIMIT 1',
            ['date' => $lastDate, 'site' => $site]
        );

        return $row ?: null;
    }

    private function buildFallbackTypes(Connection $conn, string $site): array
    {
        $siteTf = $this->findTrafficSite($conn, $site, ['TF']);
        $siteLm = $this->findTrafficSite($conn, $site, ['LM', 'LMB', 'LM2', 'FDD']);
        $siteTc = $this->findTrafficSite($conn, $site, ['TC', 'TD', 'TDD']);
        $sitePlain = $this->existingTrafficSite($conn, $site);

        return [
            'classification' => $siteTf ? 'TF' : 'PLAIN',
            'hasTF' => $siteTf !== null,
            'hasLM' => $siteLm !== null,
            'hasTC' => $siteTc !== null,
            'isPlain' => $sitePlain !== null && !$siteTf && !$siteLm && !$siteTc,
            'siteTF' => $siteTf,
            'siteLM' => $siteLm,
            'siteTC' => $siteTc,
            'sitePlain' => $sitePlain,
            'defaultMode' => $siteTf ? 'TF' : ($sitePlain ? null : ($siteLm ? 'LM' : ($siteTc ? 'TC' : null))),
        ];
    }

    private function getDefaultMode(string $classification, ?string $siteTf, ?string $siteLm, ?string $siteTc, bool $isPlain): ?string
    {
        if ($isPlain) {
            return null;
        }

        if ($classification === 'TF' && $siteTf !== null) {
            return 'TF';
        }

        if ($siteLm !== null) {
            return 'LM';
        }

        if ($siteTc !== null) {
            return 'TC';
        }

        return null;
    }

    private function existingTrafficSite(Connection $conn, ?string $site): ?string
    {
        if (!$site) {
            return null;
        }

        $row = $conn->fetchOne(
            'SELECT site FROM trafic_historique WHERE UPPER(site) = UPPER(:site) LIMIT 1',
            ['site' => $site]
        );

        return $row ? (string) $row : null;
    }

    private function findTrafficSite(Connection $conn, string $baseSite, array $suffixes): ?string
    {
        if ($suffixes === []) {
            return $this->existingTrafficSite($conn, $baseSite);
        }

        foreach ($suffixes as $suffix) {
            $site = $this->existingTrafficSite($conn, $baseSite . '_' . $suffix);
            if ($site !== null) {
                return $site;
            }
        }

        $rows = $conn->fetchFirstColumn(
            'SELECT DISTINCT site FROM trafic_historique WHERE UPPER(site) LIKE UPPER(:prefix) ORDER BY site',
            ['prefix' => $baseSite . '_%']
        );

        foreach ($rows as $row) {
            foreach ($suffixes as $suffix) {
                if (str_starts_with(strtoupper((string) $row), strtoupper($baseSite . '_' . $suffix))) {
                    return (string) $row;
                }
            }
        }

        return null;
    }

    private function fetchTrafficRows(Connection $conn, string $siteName, Request $request): array
    {
        $startParam = $request->query->get('start');
        $endParam = $request->query->get('end');
        $start = $startParam ? \DateTime::createFromFormat('Y-m-d', $startParam)->setTime(0, 0, 0) : new \DateTime('2025-01-01');
        $end = $endParam ? \DateTime::createFromFormat('Y-m-d', $endParam)->setTime(23, 59, 59) : new \DateTime('2030-12-31');

        $rows = $conn->fetchAllAssociative(
            'SELECT date_heure, max_speed
             FROM trafic_historique
             WHERE site = :site AND date_heure BETWEEN :start AND :end
             ORDER BY date_heure ASC',
            [
                'site' => $siteName,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        return array_map(static fn (array $row): array => [
            'datetime' => (new \DateTime($row['date_heure']))->format('Y-m-d\TH:i:s'),
            'max_speed' => (float) $row['max_speed'],
        ], $rows);
    }
}
