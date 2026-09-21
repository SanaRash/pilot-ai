<?php

namespace App\Security;

final class EmailWebhookAuthenticator
{
    private const int MAX_HEADER_LENGTH = 512;
    private const int MAX_TOKEN_LENGTH = 256;

    public function __construct(private readonly string $secret)
    {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->secret;
    }

    public function authenticate(?string $authorizationHeader): bool
    {
        if (!$this->isConfigured() || null === $authorizationHeader || '' === $authorizationHeader) {
            return false;
        }

        if (strlen($authorizationHeader) > self::MAX_HEADER_LENGTH) {
            return false;
        }

        if (1 !== preg_match('/\ABearer ([^\s,]+)\z/i', $authorizationHeader, $matches)) {
            return false;
        }

        $providedSecret = $matches[1];

        if (strlen($providedSecret) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        return hash_equals($this->secret, $providedSecret);
    }
}
