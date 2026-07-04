<?php

namespace App\Command;

use App\Service\Ia\AnomalyDetectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:detect-anomalies',
    description: 'Détecte les anomalies de trafic et insère les alertes dans site_alert',
)]
class DetectAnomaliesCommand extends Command
{
    public function __construct(private AnomalyDetectionService $anomalyService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Détecte sans insérer en base')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de sites à analyser', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = max(1, (int) $input->getOption('limit'));

        $io->title('Détection des anomalies de trafic');

        if ($dryRun) {
            $io->note('Mode dry-run : détection sans insertion en base.');
        }

        try {
            $result = $this->anomalyService->detectAnomaliesForAllSites(!$dryRun);

            $status  = $result['status'] ?? 'unknown';
            $nbSites = $result['total_sites_analyses'] ?? 0;
            $nbAnom  = $result['total_anomalies'] ?? 0;
            $nbInserted = $result['alertes_inserees'] ?? 0;

            if ($status === 'error') {
                $io->error($result['message'] ?? 'Erreur inconnue');
                return Command::FAILURE;
            }

            $io->definitionList(
                ['Sites analysés'     => $nbSites],
                ['Anomalies détectées' => $nbAnom],
                ['Alertes insérées'   => $dryRun ? '— (dry-run)' : $nbInserted],
            );

            if ($nbAnom === 0) {
                $io->success('Aucune anomalie détectée.');
            } elseif ($dryRun) {
                $io->info("$nbAnom anomalie(s) détectée(s) — aucune insertion (dry-run).");
            } else {
                $io->success("$nbAnom anomalie(s) détectée(s), $nbInserted alerte(s) insérée(s).");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
