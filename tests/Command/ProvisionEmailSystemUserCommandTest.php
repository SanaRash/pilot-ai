<?php

declare(strict_types=1);

use App\Kernel;
use App\Entity\User;
use App\Service\EmailIngestionUserResolver;
use App\Service\EmailIngestionUserResolutionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureProvisioningTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$systemEmail = 'email-ingestion-test-'.bin2hex(random_bytes(8)).'@pilot-ai.internal';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$previousEnvironment = [
    'env' => $_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] ?? null,
    'server' => $_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] ?? null,
    'putenv' => getenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL'),
];
$_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $systemEmail;
$_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $systemEmail;
putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL='.$systemEmail);

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$entityManager = $container->get('doctrine')->getManager();
$connection = $entityManager->getConnection();
$connection->beginTransaction();

try {
    $application = new Application($kernel);
    $command = $application->find('app:provision-email-system-user');
    $commandTester = new CommandTester($command);
    ensureProvisioningTest(
        0 === $commandTester->execute([]),
        'The system user must be provisioned successfully.',
    );

    $userRepository = $entityManager->getRepository(User::class);
    $user = $userRepository->findEmailIngestionUser($systemEmail);
    ensureProvisioningTest(null !== $user, 'The provisioned system user must be persisted.');
    ensureProvisioningTest('Système' === $user->getFirstname(), 'The system firstname is incorrect.');
    ensureProvisioningTest('Ingestion e-mail' === $user->getLastname(), 'The system lastname is incorrect.');
    ensureProvisioningTest(['ROLE_USER'] === $user->getRoles(), 'The system user must have no business role.');
    ensureProvisioningTest(true === $user->isActive(), 'The system user must be active.');
    ensureProvisioningTest('' !== $user->getPassword(), 'The system password hash must be generated.');
    ensureProvisioningTest(
        0 === $commandTester->execute([]),
        'Provisioning the same system user must be idempotent.',
    );

    $resolver = new EmailIngestionUserResolver($userRepository, $systemEmail);
    ensureProvisioningTest($user === $resolver->resolve(), 'The resolver must return the configured system user.');

    $missingResolver = new EmailIngestionUserResolver(
        $userRepository,
        'missing-email-ingestion@pilot-ai.internal',
    );

    try {
        $missingResolver->resolve();
        throw new RuntimeException('A missing system user must be rejected.');
    } catch (EmailIngestionUserResolutionException) {
    }

    $user->setFirstname('Utilisateur incompatible');
    $entityManager->flush();
    ensureProvisioningTest(
        1 === $commandTester->execute([]),
        'An incompatible existing user must be rejected.',
    );

    $user->setFirstname('Système');
    $user->setIsActive(false);
    $entityManager->flush();

    try {
        $resolver->resolve();
        throw new RuntimeException('An inactive system user must be rejected.');
    } catch (EmailIngestionUserResolutionException) {
    }
} finally {
    $connection->rollBack();
    $kernel->shutdown();

    if (null === $previousEnvironment['env']) {
        unset($_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL']);
    } else {
        $_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $previousEnvironment['env'];
    }

    if (null === $previousEnvironment['server']) {
        unset($_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL']);
    } else {
        $_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $previousEnvironment['server'];
    }

    if (false === $previousEnvironment['putenv']) {
        putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL');
    } else {
        putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL='.$previousEnvironment['putenv']);
    }
}
