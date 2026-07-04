<?php

namespace App\Service\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatConversationService
{
    public function __construct(
        private readonly ChatConversationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(User $user): array
    {
        return array_map(
            fn (ChatConversation $conversation): array => $this->serialize($conversation),
            $this->repository->findByUser($user)
        );
    }

    public function findForUser(string $publicId, User $user): ?ChatConversation
    {
        return $this->repository->findOneByPublicIdForUser($publicId, $user);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function upsert(User $user, string $publicId, string $title, array $messages): ChatConversation
    {
        $conversation = $this->repository->findOneByPublicIdForUser($publicId, $user);
        if ($conversation === null) {
            $conversation = new ChatConversation($user, $publicId);
            $this->entityManager->persist($conversation);
        }

        $conversation
            ->setTitle(mb_substr(trim($title) ?: 'Nouvelle conversation', 0, 255))
            ->setMessages($this->sanitizeMessages($messages));

        $this->entityManager->flush();

        return $conversation;
    }

    public function delete(string $publicId, User $user): bool
    {
        $conversation = $this->repository->findOneByPublicIdForUser($publicId, $user);
        if ($conversation === null) {
            return false;
        }

        $this->entityManager->remove($conversation);
        $this->entityManager->flush();

        return true;
    }

    public function deleteAllForUser(User $user): int
    {
        $conversations = $this->repository->findByUser($user, 500);
        foreach ($conversations as $conversation) {
            $this->entityManager->remove($conversation);
        }
        $this->entityManager->flush();

        return count($conversations);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->getPublicId(),
            'title' => $conversation->getTitle(),
            'createdAt' => $conversation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $conversation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'messages' => $conversation->getMessages(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeMessages(array $messages): array
    {
        $sanitized = [];
        foreach (array_slice($messages, -100) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim(mb_substr((string) ($message['content'] ?? ''), 0, 8000));
            if ($content === '' && empty($message['system'])) {
                continue;
            }

            $entry = [
                'id' => (string) ($message['id'] ?? uniqid('msg_', true)),
                'role' => $role,
                'content' => $content,
            ];

            if (!empty($message['system'])) {
                $entry['system'] = true;
            }

            if (!empty($message['source'])) {
                $entry['source'] = (string) $message['source'];
            }

            if (!empty($message['rawData'])) {
                $entry['rawData'] = mb_substr((string) $message['rawData'], 0, 12000);
            }

            $sanitized[] = $entry;
        }

        return $sanitized;
    }
}
