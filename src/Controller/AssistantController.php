<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Chat\ChatConversationService;
use App\Service\Chat\ChatPdfExporter;
use App\Service\Rag\NetwonRagAgent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/assistant')]
class AssistantController extends AbstractController
{
    private const RATE_LIMIT = 12;
    private const RATE_WINDOW = 60;
    private const MAX_HISTORY_MESSAGES = 8;
    private const MAX_HISTORY_CONTENT = 2000;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ChatConversationService $conversationService,
        private readonly ChatPdfExporter $pdfExporter,
    ) {
    }

    #[Route('', name: 'assistant', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAllowedChatUser();

        return $this->render('dashboard/chatbot.html.twig', [
            'report_only' => $this->isControleur(),
        ]);
    }

    #[Route('/controleur/rapport', name: 'controleur_rapport', methods: ['GET'])]
    public function controleurRapport(): Response
    {
        if (!$this->isControleur()) {
            throw $this->createAccessDeniedException('Accès réservé au rôle contrôleur.');
        }

        return $this->render('controleur/rapport.html.twig', [
            'ask_url'    => $this->generateUrl('assistant_ask'),
            'stream_url' => $this->generateUrl('assistant_stream'),
        ]);
    }

    #[Route('/ask', name: 'assistant_ask', methods: ['POST'])]
    public function ask(Request $request, NetwonRagAgent $ragAgent): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();
        $this->assertNotRateLimited();

        [$question, $history, $options] = $this->parsePayload($request);

        if ($this->isControleur() && $this->isSiteSpecificQuestion($question)) {
            return $this->json($this->buildRestrictedResponse());
        }

        return $this->json($ragAgent->answer($question, $history, $options));
    }

    #[Route('/stream', name: 'assistant_stream', methods: ['POST'])]
    public function askStream(Request $request, NetwonRagAgent $ragAgent): StreamedResponse
    {
        $this->denyUnlessAllowedChatUser();
        $this->assertNotRateLimited();

        [$question, $history, $options] = $this->parsePayload($request);

        return new StreamedResponse(
            function () use ($ragAgent, $question, $history, $options): void {
                $send = static function (array $payload): void {
                    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                };

                $send(['type' => 'start']);

                if ($this->isControleur() && $this->isSiteSpecificQuestion($question)) {
                        $restricted = $this->buildRestrictedResponse();
                        $send(['type' => 'chunk', 'content' => $restricted['answer']]);
                        $send(['type' => 'done', 'source_type' => 'restricted', 'sources' => []]);
                        return;
                    }

                    try {
                    $meta = $ragAgent->answerWithStream(
                        $question,
                        $history,
                        static function (string $chunk) use ($send): void {
                            if ($chunk !== '') {
                                $send(['type' => 'chunk', 'content' => $chunk]);
                            }
                        },
                        $options
                    );
                    $send(['type' => 'done'] + $meta);
                } catch (\Throwable) {
                    $send([
                        'type' => 'error',
                        'message' => 'Erreur lors de la generation de la reponse.',
                    ]);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    #[Route('/suggestions', name: 'assistant_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, NetwonRagAgent $ragAgent): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();

        $history = [];
        $raw = $request->query->get('history', '[]');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach (array_slice($decoded, -self::MAX_HISTORY_MESSAGES) as $message) {
                    if (!is_array($message)) {
                        continue;
                    }
                    $content = trim(mb_substr((string) ($message['content'] ?? ''), 0, self::MAX_HISTORY_CONTENT));
                    if ($content === '') {
                        continue;
                    }
                    $history[] = [
                        'role' => ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                        'content' => $content,
                    ];
                }
            }
        }

        return $this->json([
            'suggestions' => $ragAgent->getContextualSuggestions($history),
        ]);
    }

    #[Route('/conversations', name: 'assistant_conversations_list', methods: ['GET'])]
    public function listConversations(): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();

        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'conversations' => $this->conversationService->listForUser($user),
        ]);
    }

    #[Route('/conversations', name: 'assistant_conversations_save', methods: ['PUT'])]
    public function saveConversation(Request $request): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();

        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $publicId = trim((string) ($payload['id'] ?? ''));
        if ($publicId === '') {
            return $this->json(['error' => 'Identifiant conversation requis.'], Response::HTTP_BAD_REQUEST);
        }

        $title = (string) ($payload['title'] ?? 'Nouvelle conversation');
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $conversation = $this->conversationService->upsert($user, $publicId, $title, $messages);

        return $this->json($this->conversationService->serialize($conversation));
    }

    #[Route('/conversations/clear', name: 'assistant_conversations_clear', methods: ['DELETE'])]
    public function clearConversations(): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();

        /** @var User $user */
        $user = $this->getUser();
        $count = $this->conversationService->deleteAllForUser($user);

        return $this->json(['deleted' => $count]);
    }

    #[Route('/conversations/{publicId}', name: 'assistant_conversations_delete', methods: ['DELETE'])]
    public function deleteConversation(string $publicId): JsonResponse
    {
        $this->denyUnlessAllowedChatUser();

        /** @var User $user */
        $user = $this->getUser();
        $deleted = $this->conversationService->delete($publicId, $user);

        if (!$deleted) {
            throw new NotFoundHttpException('Conversation introuvable.');
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/conversations/{publicId}/export', name: 'assistant_conversation_export', methods: ['GET'])]
    public function exportConversation(string $publicId): Response
    {
        $this->denyUnlessAllowedChatUser();

        /** @var User $user */
        $user = $this->getUser();
        $conversation = $this->conversationService->findForUser($publicId, $user);
        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation introuvable.');
        }

        $pdf = $this->pdfExporter->export($conversation, $user);
        $filename = sprintf('netwon-rapport-%s.pdf', preg_replace('/[^a-z0-9_-]+/i', '-', $conversation->getTitle()));

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * @return array{0: string, 1: array<int, array{role: string, content: string}>, 2: array{expert: bool}}
     */
    private function parsePayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $question = is_array($payload) ? (string) ($payload['message'] ?? '') : '';
        $history = [];
        $expert = is_array($payload) && !empty($payload['expert']);

        if (is_array($payload) && isset($payload['history']) && is_array($payload['history'])) {
            foreach (array_slice($payload['history'], -self::MAX_HISTORY_MESSAGES) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
                $content = trim(mb_substr((string) ($message['content'] ?? ''), 0, self::MAX_HISTORY_CONTENT));
                if ($content === '') {
                    continue;
                }

                $history[] = ['role' => $role, 'content' => $content];
            }
        }

        return [$question, $history, ['expert' => $expert]];
    }

    /**
     * Full chatbot (/assistant page): superuser + ingénieurs + support — NOT admin, NOT controleur.
     */
    private function denyUnlessFullChatUser(): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        if (in_array($user->getRole(), ['admin', 'controleur'], true)) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }
    }

    /**
     * API endpoints (/ask, /stream): all roles except admin — controleur uses mini-chatbot.
     */
    private function denyUnlessAllowedChatUser(): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        if ($user->getRole() === 'admin') {
            throw $this->createAccessDeniedException('Le chatbot n\'est pas disponible pour les administrateurs.');
        }
    }

    private function isControleur(): bool
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        return $user instanceof \App\Entity\User && $user->getRole() === 'controleur';
    }

    /**
     * Detect site-specific questions by matching site code patterns (e.g. ARI_0005, BEN_001).
     */
    private function isSiteSpecificQuestion(string $question): bool
    {
        // Site codes: 2-6 uppercase letters + underscore + 3-5 digits (optional suffix)
        return (bool) preg_match('/\b[A-Za-z]{2,6}_[0-9]{3,5}\b/', $question);
    }

    private function buildRestrictedResponse(): array
    {
        return [
            'answer'       => "⛔ Cette information n'est pas accessible pour votre profil.\n\nVous pouvez utiliser :\n• **/rapport** — générer un rapport réseau global\n• **/stats** — consulter les statistiques réseau\n• **/tickets** — voir l'état des tickets",
            'sources'      => [],
            'source_type'  => 'restricted',
            'is_fast_path' => true,
        ];
    }

    private function assertNotRateLimited(): void
    {
        $user = $this->getUser();
        $identifier = $user?->getUserIdentifier() ?? 'anonymous';
        $key = 'chatbot_rate_' . md5($identifier);

        $count = $this->cache->get($key, function (ItemInterface $item): int {
            $item->expiresAfter(self::RATE_WINDOW);

            return 0;
        });

        if ($count >= self::RATE_LIMIT) {
            throw new TooManyRequestsHttpException(
                self::RATE_WINDOW,
                'Trop de requetes. Reessayez dans une minute.'
            );
        }

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($count): int {
            $item->expiresAfter(self::RATE_WINDOW);

            return $count + 1;
        });
    }
}
