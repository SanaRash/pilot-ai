<?php

namespace App\Controller\Api;

use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailTicketController extends AbstractController
{
    private const array ALLOWED_FIELDS = ['sender', 'subject', 'content', 'messageId'];
    private const int MAX_REQUEST_BYTES = 1_000_000;

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

        return $this->error(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'email_ingestion_not_configured',
            'L’ingestion des tickets par e-mail n’est pas encore configurée.',
        );
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
}
