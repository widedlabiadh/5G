<?php
// src/Service/AlertService.php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class AlertService
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private string $appName = 'PFE 5G Monitor',
        private string $fromEmail = 'alertes@pfe5g.tn'
    ) {}

    /**
     * Récupère les alertes actives (non lues)
     * Utilisé dans le dashboard
     */
    public function getActiveAlerts(): array
    {
        return $this->getAlertesNonLues();
    }

    public function getAlertesNonLues(): array
    {
        return $this->connection->fetchAllAssociative("
            SELECT id, site, etat, trafic_j, capacite_mbps, taux_utilisation,
                   type_trans, message, date_alerte
            FROM site_alert
            WHERE alerte_lue = FALSE
            ORDER BY date_alerte DESC
        ");
    }

    public function countAlertesNonLues(): int
    {
        return (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM site_alert WHERE alerte_lue = FALSE
        ");
    }

    public function marquerLue(int $id): void
    {
        $this->connection->executeStatement(
            "UPDATE site_alert SET alerte_lue = TRUE WHERE id = :id",
            ['id' => $id]
        );
    }

    public function marquerToutesLues(): void
    {
        $this->connection->executeStatement("UPDATE site_alert SET alerte_lue = TRUE");
    }

    public function getHistoriqueAlertes(int $limit = 100, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative("
            SELECT id, site, etat, trafic_j, capacite_mbps, taux_utilisation,
                   type_trans, classification, message, date_alerte, alerte_lue, email_envoye
            FROM site_alert
            ORDER BY date_alerte DESC
            LIMIT :limit OFFSET :offset
        ", ['limit' => $limit, 'offset' => $offset]);
    }

    public function envoyerAlertesParEmail(): int
    {
        $alertes = $this->connection->fetchAllAssociative("
            SELECT id, site, etat, trafic_j, capacite_mbps, taux_utilisation,
                   type_trans, classification, message, date_alerte
            FROM site_alert
            WHERE email_envoye = FALSE AND alerte_lue = FALSE
        ");

        if (empty($alertes)) {
            return 0;
        }

        $superusers = $this->connection->fetchAllAssociative("
            SELECT email FROM \"user\" WHERE role = 'superuser' AND is_active = TRUE
        ");

        if (empty($superusers)) {
            return 0;
        }

        $congestes = array_filter($alertes, fn($a) => $a['etat'] === 'CONGESTIONNE');
        $brides = array_filter($alertes, fn($a) => $a['etat'] === 'BRIDE');
        $ids = array_column($alertes, 'id');

        $html = $this->buildEmailHtml($congestes, $brides);
        $sujet = sprintf('[PFE 5G] ⚠️ %d alerte(s) - %d congestion, %d bridage',
            count($alertes), count($congestes), count($brides));

        foreach ($superusers as $user) {
            try {
                $email = (new Email())
                    ->from(new Address($this->fromEmail, $this->appName))
                    ->to($user['email'])
                    ->subject($sujet)
                    ->html($html);
                $this->mailer->send($email);
            } catch (\Exception $e) {
                error_log('Erreur envoi email: ' . $e->getMessage());
            }
        }

        $this->connection->executeStatement(
            "UPDATE site_alert SET email_envoye = TRUE WHERE id = ANY(:ids)",
            ['ids' => '{' . implode(',', $ids) . '}']
        );

        return count($alertes);
    }

    private function buildEmailHtml(array $congestes, array $brides): string
    {
        $date = (new \DateTime())->format('d/m/Y H:i');
        
        $html = '<!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><title>Alertes réseau</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f4f4f4;padding:20px}
            .container{max-width:800px;margin:0 auto;background:#fff;border-radius:8px;padding:20px}
            h2{color:#333;border-bottom:2px solid #ddd;padding-bottom:10px}
            .alert-red{color:#dc2626}
            .alert-orange{color:#ea580c}
            table{border-collapse:collapse;width:100%;margin:15px 0}
            th,td{border:1px solid #ddd;padding:8px;text-align:left}
            th{background:#f3f4f6}
            .badge-red{background:#dc2626;color:#fff;padding:2px 8px;border-radius:12px;font-size:12px}
            .badge-orange{background:#ea580c;color:#fff;padding:2px 8px;border-radius:12px;font-size:12px}
            .footer{font-size:11px;color:#999;margin-top:20px;text-align:center}
        </style>
        </head>
        <body>
        <div class="container">
            <h2>⚠️ Rapport d\'alertes réseau PFE 5G</h2>
            <p>Généré le <strong>' . $date . '</strong></p>';

        if (!empty($congestes)) {
            $html .= '<h3 class="alert-red">🔴 CONGESTION (' . count($congestes) . ')</h3>
            <table>
                <tr><th>Site</th><th>Trafic J</th><th>Capacité</th><th>Utilisation</th><th>Type</th></tr>';
            foreach ($congestes as $a) {
                $html .= sprintf('
                    <tr>
                        <td><strong>%s</strong></td>
                        <td>%.1f Mbps</td>
                        <td>%.0f Mbps</td>
                        <td><span class="badge-red">%.1f%%</span></td>
                        <td>%s</td>
                    </tr>',
                    htmlspecialchars($a['site']),
                    $a['trafic_j'],
                    $a['capacite_mbps'],
                    $a['taux_utilisation'],
                    htmlspecialchars($a['type_trans'] ?? '—')
                );
            }
            $html .= '</table>';
        }

        if (!empty($brides)) {
            $html .= '<h3 class="alert-orange">🟠 BRIDAGE (' . count($brides) . ')</h3>
            <table>
                <tr><th>Site</th><th>Trafic J</th><th>Capacité</th><th>Utilisation</th><th>Type</th></tr>';
            foreach ($brides as $a) {
                $html .= sprintf('
                    <tr>
                        <td><strong>%s</strong></td>
                        <td>%.1f Mbps</td>
                        <td>%.0f Mbps</td>
                        <td><span class="badge-orange">%.1f%%</span></td>
                        <td>%s</td>
                    </tr>',
                    htmlspecialchars($a['site']),
                    $a['trafic_j'],
                    $a['capacite_mbps'],
                    $a['taux_utilisation'],
                    htmlspecialchars($a['type_trans'] ?? '—')
                );
            }
            $html .= '</table>';
        }

        $html .= '<div class="footer">Ce rapport est généré automatiquement par PFE 5G Monitor.</div>';
        $html .= '</div></body></html>';
        
        return $html;
    }
}