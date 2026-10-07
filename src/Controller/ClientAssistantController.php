<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Assistant\AssistantException;
use App\AI\Assistant\AssistantHistoryMessage;
use App\AI\Assistant\AssistantService;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use App\Service\KnowledgeSearchService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientAssistantController extends AbstractController
{
    private const MAX_HISTORY_MESSAGES = 6;
    private const MAX_USER_HISTORY_MESSAGE_LENGTH = 1_000;
    private const MAX_ASSISTANT_HISTORY_MESSAGE_LENGTH = 2_000;
    private const MAX_HISTORY_TOTAL_LENGTH = 4_000;
    private const MAX_DRAFT_TITLE_LENGTH = 255;
    private const MAX_DRAFT_DESCRIPTION_LENGTH = 50_000;
    public const TICKET_DRAFT_SESSION_KEY = 'client_assistant_ticket_draft';

    #[Route('/client/assistant/message', name: 'app_client_assistant_message', methods: ['POST'])]
    public function message(
        Request $request,
        TicketRepository $ticketRepository,
        KnowledgeSearchService $knowledgeSearchService,
        AssistantService $assistantService,
        LoggerInterface $logger,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isGranted('ROLE_CLIENT')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json([
                'success' => false,
                'error' => 'Le JSON transmis est invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('client-assistant-message', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json([
                'success' => false,
                'error' => 'La session a expiré. Actualisez la page et réessayez.',
            ], Response::HTTP_FORBIDDEN);
        }

        $message = $payload['message'] ?? null;
        if (!is_string($message)) {
            return $this->json(['success' => false, 'error' => 'Saisissez un message.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = trim($message);
        if ('' === $message || mb_strlen($message, 'UTF-8') > 2_000) {
            return $this->json([
                'success' => false,
                'error' => 'Le message doit contenir entre 1 et 2000 caractères.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $history = $this->validateHistory($payload['history'] ?? []);
        if (null === $history) {
            return $this->json([
                'success' => false,
                'error' => 'L’historique de conversation est invalide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = null;
        if (array_key_exists('ticketId', $payload) && null !== $payload['ticketId']) {
            if (!is_int($payload['ticketId']) && !(is_string($payload['ticketId']) && ctype_digit($payload['ticketId']))) {
                return $this->json(['success' => false, 'error' => 'Le contexte du ticket est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $ticket = $ticketRepository->findOneBy([
                'id' => (int) $payload['ticketId'],
                'createdBy' => $user,
            ]);
            if (!$ticket instanceof Ticket) {
                throw $this->createNotFoundException();
            }
        }

        $articles = $knowledgeSearchService->search($message, $ticket);

        try {
            $reply = $assistantService->reply($message, $articles, $ticket, $history);
        } catch (AssistantException $exception) {
            $logger->warning('La réponse de l’assistant client est indisponible.', [
                'event' => 'client_assistant_unavailable',
                'exception' => $exception::class,
                'ticketId' => $ticket?->getId(),
            ] + $exception->getContext());

            return $this->json([
                'success' => false,
                'error' => 'Pilot AI n’est pas disponible pour le moment.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'message' => [
                'author' => 'BOT',
                'content' => $reply->answer,
            ],
            'needsTechnician' => $reply->needsTechnician,
        ]);
    }

    #[Route('/client/assistant/ticket-draft', name: 'app_client_assistant_ticket_draft', methods: ['POST'])]
    public function ticketDraft(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isGranted('ROLE_CLIENT')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json([
                'success' => false,
                'error' => 'Le JSON transmis est invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('client-assistant-message', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json([
                'success' => false,
                'error' => 'La session a expiré. Actualisez la page et réessayez.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->hasExactlyKeys($payload, array_key_exists('history', $payload) ? ['question', 'answer', 'history'] : ['question', 'answer'])) {
            return $this->json([
                'success' => false,
                'error' => 'La demande de brouillon est invalide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $question = $payload['question'];
        $answer = $payload['answer'];
        if (!is_string($question) || !is_string($answer)) {
            return $this->json([
                'success' => false,
                'error' => 'La demande de brouillon est invalide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $question = trim($question);
        $answer = trim($answer);
        if ('' === $question || mb_strlen($question, 'UTF-8') > 2_000 || mb_strlen($answer, 'UTF-8') > 2_000) {
            return $this->json([
                'success' => false,
                'error' => 'La demande de brouillon est invalide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $history = $this->validateHistory($payload['history'] ?? []);
        if (null === $history) {
            return $this->json([
                'success' => false,
                'error' => 'La demande de brouillon est invalide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->getSession()->set(self::TICKET_DRAFT_SESSION_KEY, [
            'title' => $this->buildDraftTitle($question),
            'description' => $this->buildDraftDescription($question, $answer, $history),
        ]);

        if (str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return $this->json([
                'success' => true,
                'redirectUrl' => $this->generateUrl('app_client_ticket_new'),
            ]);
        }

        return $this->redirectToRoute('app_client_ticket_new', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<AssistantHistoryMessage>|null
     */
    private function validateHistory(mixed $history): ?array
    {
        if (null === $history) {
            return [];
        }

        if (!is_array($history) || !array_is_list($history) || count($history) > self::MAX_HISTORY_MESSAGES) {
            return null;
        }

        $validated = [];
        $totalLength = 0;
        foreach ($history as $message) {
            if (!is_array($message) || !$this->hasExactlyKeys($message, ['role', 'content'])) {
                return null;
            }

            $role = $message['role'];
            $content = $message['content'];
            if (!in_array($role, [AssistantHistoryMessage::ROLE_USER, AssistantHistoryMessage::ROLE_ASSISTANT], true) || !is_string($content)) {
                return null;
            }

            $content = trim($content);
            $contentLength = mb_strlen($content, 'UTF-8');
            $totalLength += $contentLength;
            $maxMessageLength = AssistantHistoryMessage::ROLE_ASSISTANT === $role
                ? self::MAX_ASSISTANT_HISTORY_MESSAGE_LENGTH
                : self::MAX_USER_HISTORY_MESSAGE_LENGTH;
            if ('' === $content || $contentLength > $maxMessageLength || $totalLength > self::MAX_HISTORY_TOTAL_LENGTH) {
                return null;
            }

            $validated[] = new AssistantHistoryMessage($role, $content);
        }

        return $validated;
    }

    private function buildDraftTitle(string $question): string
    {
        $firstLine = trim((string) preg_split('/[\r\n.?!]/u', $question, 2)[0]);
        $title = '' === $firstLine ? $question : $firstLine;

        return mb_substr($title, 0, self::MAX_DRAFT_TITLE_LENGTH, 'UTF-8');
    }

    /**
     * @param list<AssistantHistoryMessage> $history
     */
    private function buildDraftDescription(string $question, string $answer, array $history): string
    {
        $lines = [
            'Question initiale :',
            $this->toDraftPlainText($question),
        ];

        if ('' !== $answer) {
            $lines[] = '';
            $lines[] = 'Réponse de Pilot AI :';
            $lines[] = $this->toDraftPlainText($answer);
        }

        if ([] !== $history) {
            $lines[] = '';
            $lines[] = 'Contexte de conversation :';
            foreach ($history as $message) {
                $label = AssistantHistoryMessage::ROLE_USER === $message->role ? 'Client' : 'Pilot AI';
                $lines[] = sprintf('- %s : %s', $label, $this->toDraftPlainText($message->content));
            }
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_DRAFT_DESCRIPTION_LENGTH, 'UTF-8');
    }

    private function toDraftPlainText(string $content): string
    {
        return str_replace(['**', '__', '`'], '', $content);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $expectedKeys
     */
    private function hasExactlyKeys(array $payload, array $expectedKeys): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }
}
