<?php
// src/Command/EnvoyerAlertesCommand.php

namespace App\Command;

use App\Service\AlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:envoyer-alertes',
    description: 'Envoie les alertes par email aux superusers',
)]
class EnvoyerAlertesCommand extends Command
{
    public function __construct(private AlertService $alertService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📧 Envoi des alertes');

        try {
            $count = $this->alertService->envoyerAlertesParEmail();
            
            if ($count > 0) {
                $io->success("✅ $count alerte(s) envoyée(s) par email.");
            } else {
                $io->info("ℹ️ Aucune alerte à envoyer.");
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("❌ Erreur : " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}