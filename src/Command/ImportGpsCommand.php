<?php
// src/Command/ImportGpsCommand.php - Version améliorée

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-gps', description: 'Importe les coordonnées GPS depuis un fichier CSV')]
class ImportGpsCommand extends Command
{
    private Connection $conn;

    public function __construct(Connection $conn)
    {
        parent::__construct();
        $this->conn = $conn;
    }

    protected function configure(): void
    {
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Chemin vers le fichier CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getOption('file');
        
        if (!$filePath) {
            $io->error("Veuillez spécifier le fichier avec --file=chemin.csv");
            return Command::FAILURE;
        }
        
        $fullPath = getcwd() . '/' . $filePath;
        if (!file_exists($fullPath)) {
            $io->error("Fichier non trouvé: " . $fullPath);
            return Command::FAILURE;
        }
        
        // Lire le fichier CSV avec gestion des accents
        $content = file_get_contents($fullPath);
        // Convertir en UTF-8 si nécessaire
        $content = utf8_encode($content);
        $lines = explode("\n", $content);
        $header = str_getcsv(array_shift($lines), ',');
        
        $io->info("En-têtes trouvées: " . implode(', ', $header));
        
        // Trouver les index des colonnes
        $siteIndex = array_search('sites', $header);
        $latIndex = array_search('Latitude_Sector', $header);
        $lngIndex = array_search('Longitude_Sector', $header);
        
        if ($siteIndex === false || $latIndex === false || $lngIndex === false) {
            $io->error("Colonnes requises non trouvées. Attendu: sites, Latitude_Sector, Longitude_Sector");
            return Command::FAILURE;
        }
        
        $updated = 0;
        $notFound = 0;
        $invalid = 0;
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $row = str_getcsv(trim($line), ',');
            if (count($row) < 3) continue;
            
            $siteName = trim($row[$siteIndex]);
            $latitude = (float)$row[$latIndex];
            $longitude = (float)$row[$lngIndex];
            
            if (empty($siteName) || $latitude == 0 || $longitude == 0) {
                $invalid++;
                continue;
            }
            
            // Vérifier si le site existe dans analyse_resultat
            $exists = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM analyse_resultat WHERE site = :site',
                ['site' => $siteName]
            );
            
            if ($exists > 0) {
                $this->conn->executeStatement(
                    'UPDATE analyse_resultat SET latitude = :lat, longitude = :lng WHERE site = :site',
                    ['lat' => $latitude, 'lng' => $longitude, 'site' => $siteName]
                );
                $updated++;
                $io->writeln("✓ $siteName → ($latitude, $longitude)");
            } else {
                $notFound++;
                $io->writeln("✗ $siteName non trouvé dans analyse_resultat");
            }
        }
        
        $io->success([
            "Import terminé !",
            "✅ Sites mis à jour: $updated",
            "⚠️ Sites non trouvés: $notFound",
            "❌ Lignes invalides: $invalid"
        ]);
        
        return Command::SUCCESS;
    }
}