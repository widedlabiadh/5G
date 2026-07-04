<?php

namespace App\Service\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class ChatPdfExporter
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function export(ChatConversation $conversation, User $user): string
    {
        $html = $this->twig->render('assistant/conversation_export.html.twig', [
            'conversation' => $conversation,
            'user' => $user,
            'exportedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
