<?php
// src/Command/SyncAllCapacitiesCommand.php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync-all-capacities', description: 'Synchronise toutes les capacités avec analyse_resultat')]
class SyncAllCapacitiesCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('🔄 Synchronisation de toutes les capacités...');
        $start = microtime(true);

        // 1. BACKHAUL SHARING
        $output->write('  Backhaul Sharing... ');
        $count = $this->connection->executeStatement("
            UPDATE analyse_resultat ar
            SET capacite_mbps = cs.capacite_mbps,
                capacite_fdd_mbps = cs.capacite_mbps,
                taux_utilisation = ROUND((ar.max_trafic / NULLIF(cs.capacite_mbps, 0)) * 100, 2),
                taux_utilisation_fdd = ROUND((ar.max_trafic_fdd / NULLIF(cs.capacite_mbps, 0)) * 100, 2)
            FROM capacite_site cs
            WHERE cs.type_trans IN ('BACKBONE', 'BH OO', 'BH_TT', 'BH', 'NON_DEFINI')
              AND UPPER(ar.type_trans) IN ('BACKBONE', 'BH OO', 'BH_TT', 'BH', 'NON_DEFINI', 'BH TT', 'BH OO')
              AND UPPER(ar.site_clean) = UPPER(cs.site)
        ");
        $output->writeln("✅ $count sites");

        // 2. FH
        $output->write('  FH... ');
        $count = $this->connection->executeStatement("
            UPDATE analyse_resultat ar
            SET capacite_mbps = cs.capacite_mbps,
                capacite_fdd_mbps = cs.capacite_mbps,
                taux_utilisation = ROUND((ar.max_trafic / NULLIF(cs.capacite_mbps, 0)) * 100, 2),
                taux_utilisation_fdd = ROUND((ar.max_trafic_fdd / NULLIF(cs.capacite_mbps, 0)) * 100, 2)
            FROM capacite_site cs
            WHERE cs.type_trans = 'FH'
              AND UPPER(ar.type_trans) = UPPER(cs.type_trans)
              AND UPPER(ar.site_clean) = UPPER(cs.site)
        ");
        $output->writeln("✅ $count sites");

        // 3. FO
        $output->write('  FO... ');
        $count = $this->connection->executeStatement("
            UPDATE analyse_resultat ar
            SET capacite_mbps = cs.capacite_mbps,
                capacite_tdd_mbps = cs.capacite_mbps,
                taux_utilisation = ROUND((ar.max_trafic / NULLIF(cs.capacite_mbps, 0)) * 100, 2),
                taux_utilisation_tdd = ROUND((ar.max_trafic_tdd / NULLIF(cs.capacite_mbps, 0)) * 100, 2)
            FROM capacite_site cs
            WHERE cs.type_trans = 'FO'
              AND UPPER(ar.type_trans) = UPPER(cs.type_trans)
              AND UPPER(ar.site_clean) = UPPER(cs.site)
        ");
        $output->writeln("✅ $count sites");

        $duration = round(microtime(true) - $start, 2);
        $output->writeln("🎉 Synchronisation terminée en {$duration} secondes !");

        return Command::SUCCESS;
    }
}