<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

final class EmailIngestionUserResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly string $systemEmail,
    ) {
    }

    public function resolve(): User
    {
        $user = $this->userRepository->findEmailIngestionUser($this->systemEmail);

        if (null === $user) {
            throw new EmailIngestionUserResolutionException('Le compte système d’ingestion e-mail est introuvable.');
        }

        if (true !== $user->isActive()) {
            throw new EmailIngestionUserResolutionException('Le compte système d’ingestion e-mail est inactif.');
        }

        return $user;
    }
}
