<?php

namespace App\Controller\Api;

use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\AI\AIAnalysisResult;
use App\Entity\Ticket;
use App\Security\EmailWebhookAuthenticator;
use App\Service\EmailIngestionUserResolutionException;
use App\Service\EmailIngestionUserResolver;
use App\Service\TicketHistoryService;
use App\Service\TicketCategorySuggestionException;
use App\Service\TicketCategorySuggestionService;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailTicketController extends AbstractController
{
    private const array ALLOWED_FIELDS = ['sender', 'subject', 'content', 'messageId'];
    private const int MAX_REQUEST_BYTES = 1_000_000;

    public function __construct(
        private readonly EmailWebhookAuthenticator $authenticator,
        private readonly EmailIngestionUserResolver $userResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketHistoryService $ticketHistoryService,
        private readonly LoggerInterface $logger,
        private readonly AIService $aiService,
        private readonly TicketCategorySuggestionService $ticketCategorySuggestionService,
    ) {
    }

    #[Route(
        '/api/tickets/email',
        name: 'api_tickets_email_method_not_allowed',
        priority: -10,
    )]
    public function methodNotAllowed(): JsonResponse
    {
        $response = $this->error(
            Response::HTTP_METHOD_NOT_ALLOWED,
            'method_not_allowed',
            'Seule la méthode POST est autorisée.',
        );
        $response->headers->set('Allow', 'POST');

        return $response;
    }

    #[Route('/api/tickets/email', name: 'api_tickets_email', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->authenticator->isConfigured()) {
            return $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'email_authentication_not_configured',
                'Le service d’ingestion e-mail n’est pas configuré.',
            );
        }

        if (!$this->authenticator->authenticate($request->headers->get('Authorization'))) {
            return $this->authenticationRequired();
        }

        $contentType = $request->headers->get('Content-Type', '');

        if (1 !== preg_match('/^application\/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?\s*$/i', $contentType)) {
            return $this->error(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'unsupported_media_type',
                'Le Content-Type doit être application/json.',
            );
        }

        $contentLength = $request->headers->get('Content-Length');

        if (null !== $contentLength && ctype_digit($contentLength) && (int) $contentLength > self::MAX_REQUEST_BYTES) {
            return $this->payloadTooLarge();
        }

        $content = $request->getContent();

        if (strlen($content) > self::MAX_REQUEST_BYTES) {
            return $this->payloadTooLarge();
        }

        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(
                Response::HTTP_BAD_REQUEST,
                'invalid_json',
                'Le corps de la requête contient un JSON invalide.',
            );
        }

        if (!$decoded instanceof \stdClass) {
            return $this->validationError([
                '_payload' => 'La racine JSON doit être un objet.',
            ]);
        }

        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($decoded);
        $errors = $this->validatePayload($payload);

        if ([] !== $errors) {
            return $this->validationError($errors);
        }

        $ticketRepository = $this->entityManager->getRepository(Ticket::class);
        $existingTicket = $ticketRepository->findOneBy(['messageId' => $payload['messageId']]);

        if ($existingTicket instanceof Ticket) {
            return $this->duplicateResponse(
                (int) $existingTicket->getId(),
                (string) $existingTicket->getSource(),
                (string) $existingTicket->getStatus(),
            );
        }

        try {
            $systemUser = $this->userResolver->resolve();
        } catch (EmailIngestionUserResolutionException $exception) {
            $this->logger->error('Le compte système d’ingestion e-mail est indisponible.', [
                'event' => 'email_ingestion_system_user_unavailable',
                'step' => 'system_user_resolution',
                'exception' => $exception::class,
            ]);

            return $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'email_ingestion_system_user_unavailable',
                'Le service d’ingestion e-mail est temporairement indisponible.',
            );
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $ticket = (new Ticket())
                ->setTitle($payload['subject'])
                ->setDescription($payload['content'])
                ->setSource('EMAIL')
                ->setMessageId($payload['messageId'])
                ->setRequesterEmail($payload['sender'])
                ->setStatus(Ticket::STATUS_OPEN)
                ->setPriority(Ticket::PRIORITY_MEDIUM)
                ->setCategory(null)
                ->setAssignedTo(null)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setUpdatedAt(null)
                ->setCreatedBy($systemUser);

            $this->entityManager->persist($ticket);
            $this->ticketHistoryService->record($ticket, 'TICKET_CREATED', null, null, $systemUser);
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            if ($this->containsUniqueConstraintViolation($exception)) {
                try {
                    $existingMessage = $connection->fetchAssociative(
                        'SELECT id, source, status FROM ticket WHERE message_id = ?',
                        [$payload['messageId']],
                    );

                    if (false !== $existingMessage) {
                        return $this->duplicateResponse(
                            (int) $existingMessage['id'],
                            (string) $existingMessage['source'],
                            (string) $existingMessage['status'],
                        );
                    }
                } catch (DBALException $lookupException) {
                    $this->logger->error('La résolution du doublon de message e-mail a échoué.', [
                        'event' => 'email_ingestion_duplicate_resolution_failed',
                        'step' => 'duplicate_resolution',
                        'exception' => $lookupException::class,
                    ]);
                }
            }

            $this->logger->error('La persistance du ticket e-mail a échoué.', [
                'event' => 'email_ingestion_ticket_persistence_failed',
                'step' => 'ticket_persistence',
                'exception' => $exception::class,
            ]);

            return $this->error(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'email_ingestion_persistence_failed',
                'Le ticket e-mail n’a pas pu être créé.',
            );
        }

        $aiAnalysisStatus = 'created';

        $analysisResult = null;

        try {
            $analysisResult = $this->aiService->analyzeTicket($ticket);
        } catch (AIProviderException|AIValidationException $exception) {
            $aiAnalysisStatus = 'unavailable';
            $this->logger->warning('L’analyse IA du ticket e-mail est indisponible.', [
                'event' => 'email_ingestion_ai_unavailable',
                'step' => 'analyze',
                'exceptionClass' => $exception::class,
                'providerStatus' => $exception->getCode() ?: null,
                'ticketId' => $ticket->getId(),
            ]);
        } catch (ORMException|DBALException $exception) {
            $aiAnalysisStatus = 'unavailable';
            $this->logger->warning('La persistance de l’analyse IA du ticket e-mail a échoué.', [
                'event' => 'email_ingestion_ai_persistence_failed',
                'step' => 'persist',
                'exceptionClass' => $exception::class,
                'providerStatus' => null,
                'ticketId' => $ticket->getId(),
            ]);
        }

        if ($analysisResult instanceof AIAnalysisResult) {
            try {
                $this->ticketCategorySuggestionService->applySuggestion($ticket, $analysisResult->suggestedCategory);
            } catch (TicketCategorySuggestionException $exception) {
                $this->logger->warning('La catégorisation automatique du ticket e-mail a échoué.', [
                    'event' => 'email_ingestion_category_suggestion_failed',
                    'step' => 'categorize',
                    'exceptionClass' => $exception::class,
                    'providerStatus' => null,
                    'ticketId' => $ticket->getId(),
                ]);
            }
        }

        return new JsonResponse([
            'data' => [
                'id' => $ticket->getId(),
                'source' => 'EMAIL',
                'status' => Ticket::STATUS_OPEN,
                'aiAnalysis' => $aiAnalysisStatus,
            ],
            'duplicate' => false,
        ], Response::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];
        $unknownFields = array_diff(array_keys($payload), self::ALLOWED_FIELDS);

        if ([] !== $unknownFields) {
            $errors['_payload'] = 'Le payload contient une ou plusieurs propriétés inconnues.';
        }

        $this->validateRequiredString($payload, 'sender', 180, $errors);
        $this->validateRequiredString($payload, 'subject', 255, $errors);
        $this->validateRequiredString($payload, 'content', 50_000, $errors);
        $this->validateRequiredString($payload, 'messageId', 255, $errors);

        if (!isset($errors['sender']) && false === filter_var($payload['sender'], FILTER_VALIDATE_EMAIL)) {
            $errors['sender'] = 'Ce champ doit contenir une adresse e-mail valide.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors
     */
    private function validateRequiredString(array $payload, string $field, int $maxLength, array &$errors): void
    {
        if (!array_key_exists($field, $payload)) {
            $errors[$field] = 'Ce champ est obligatoire.';

            return;
        }

        $this->validateStringValue($payload[$field], $field, $maxLength, $errors);
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateStringValue(mixed $value, string $field, int $maxLength, array &$errors): void
    {
        if (!is_string($value)) {
            $errors[$field] = 'Ce champ doit être une chaîne de caractères.';

            return;
        }

        if (1 !== preg_match('/\S/u', $value)) {
            $errors[$field] = 'Ce champ ne doit pas être vide.';

            return;
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[$field] = sprintf('Ce champ ne doit pas dépasser %d caractères.', $maxLength);
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function validationError(array $fields): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Le payload est invalide.',
                'fields' => $fields,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private function payloadTooLarge(): JsonResponse
    {
        return $this->error(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'payload_too_large',
            'Le corps de la requête est trop volumineux.',
        );
    }

    private function authenticationRequired(): JsonResponse
    {
        $response = $this->error(
            Response::HTTP_UNAUTHORIZED,
            'authentication_required',
            'Authentification requise.',
        );
        $response->headers->set('WWW-Authenticate', 'Bearer realm="Pilot AI email ingestion"');

        return $response;
    }

    private function duplicateResponse(int $ticketId, string $source, string $status): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'id' => $ticketId,
                'source' => $source,
                'status' => $status,
            ],
            'duplicate' => true,
        ], Response::HTTP_OK);
    }

    private function containsUniqueConstraintViolation(\Throwable $exception): bool
    {
        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }
}
