<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Crée un utilisateur Pilot AI avec un rôle.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Création d’un utilisateur Pilot AI');

        $email = $io->ask('Email');

        if (!$email) {
            $io->error('L’email est obligatoire.');
            return Command::FAILURE;
        }

        $existingUser = $this->userRepository->findOneBy([
            'email' => $email,
        ]);

        if ($existingUser) {
            $io->error('Un utilisateur avec cet email existe déjà.');
            return Command::FAILURE;
        }

        $firstname = $io->ask('Prénom');

        if (!$firstname) {
            $io->error('Le prénom est obligatoire.');
            return Command::FAILURE;
        }

        $lastname = $io->ask('Nom');

        if (!$lastname) {
            $io->error('Le nom est obligatoire.');
            return Command::FAILURE;
        }

        $password = $io->askHidden('Mot de passe');

        if (!$password || strlen($password) < 6) {
            $io->error('Le mot de passe doit contenir au moins 6 caractères.');
            return Command::FAILURE;
        }

        $role = $io->choice(
            'Rôle',
            [
                'ROLE_CLIENT',
                'ROLE_TECHNICIAN',
                'ROLE_ADMIN',
            ],
            'ROLE_CLIENT'
        );

        $user = new User();

        $user->setEmail($email);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setRoles([$role]);
        $user->setIsActive(true);
        $user->setCreatedAt(new \DateTimeImmutable());

        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            $password
        );

        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Utilisateur %s %s créé avec le rôle %s.',
            $firstname,
            $lastname,
            $role
        ));

        return Command::SUCCESS;
    }
}