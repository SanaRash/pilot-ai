<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:provision-email-system-user',
    description: 'Provisionne le compte système dédié à l’ingestion e-mail.',
)]
final class ProvisionEmailSystemUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly string $systemEmail,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (
            false === filter_var($this->systemEmail, FILTER_VALIDATE_EMAIL)
            || mb_strlen($this->systemEmail) > 180
        ) {
            $io->error('PILOTAI_EMAIL_SYSTEM_USER_EMAIL doit contenir une adresse e-mail valide.');

            return Command::FAILURE;
        }

        $existingUser = $this->userRepository->findEmailIngestionUser($this->systemEmail);

        if (null !== $existingUser) {
            if (!$this->isCompatibleSystemUser($existingUser)) {
                $io->error('Un utilisateur incompatible existe déjà avec cette adresse e-mail.');

                return Command::FAILURE;
            }

            $io->success('Le compte système d’ingestion e-mail existe déjà.');

            return Command::SUCCESS;
        }

        try {
            $randomSecret = bin2hex(random_bytes(32));
        } catch (RandomException) {
            $io->error('Impossible de générer le secret du compte système.');

            return Command::FAILURE;
        }

        $user = new User();
        $user
            ->setEmail($this->systemEmail)
            ->setFirstname('Système')
            ->setLastname('Ingestion e-mail')
            ->setRoles([])
            ->setIsActive(true)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setPassword($this->passwordHasher->hashPassword($user, $randomSecret));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Le compte système d’ingestion e-mail a été provisionné.');

        return Command::SUCCESS;
    }

    private function isCompatibleSystemUser(User $user): bool
    {
        return 'Système' === $user->getFirstname()
            && 'Ingestion e-mail' === $user->getLastname()
            && ['ROLE_USER'] === $user->getRoles()
            && true === $user->isActive();
    }
}
