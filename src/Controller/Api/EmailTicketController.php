<?php

namespace App\Controller\Api;

use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\Entity\Ticket;
use App\Security\EmailWebhookAuthenticator;
use App\Service\EmailIngestionUserResolutionException;
use App\Service\EmailIngestionUserResolver;
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Exception as DBALException;
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

        try {
            $this->aiService->analyzeTicket($ticket);
        } catch (AIProviderException|AIValidationException $exception) {
            $aiAnalysisStatus = 'unavailable';
            $this->logger->warning('L’analyse IA du ticket e-mail est indisponible.', [
                'event' => 'email_ingestion_ai_unavailable',
                'step' => 'ai_analysis',
                'exception' => $exception::class,
                'ticketId' => $ticket->getId(),
            ]);
        } catch (ORMException|DBALException $exception) {
            $aiAnalysisStatus = 'unavailable';
            $this->logger->warning('La persistance de l’analyse IA du ticket e-mail a échoué.', [
                'event' => 'email_ingestion_ai_persistence_failed',
                'step' => 'ai_analysis_persistence',
                'exception' => $exception::class,
                'ticketId' => $ticket->getId(),
            ]);
        }

        return new JsonResponse([
            'data' => [
                'id' => $ticket->getId(),
                'source' => 'EMAIL',
                'status' => Ticket::STATUS_OPEN,
                'aiAnalysis' => $aiAnalysisStatus,
            ],
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

        if (array_key_exists('messageId', $payload)) {
            $this->validateStringValue($payload['messageId'], 'messageId', 255, $errors);
        }

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
}
