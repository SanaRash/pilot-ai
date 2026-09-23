<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Entity\User;
use App\Kernel;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAdminUsers(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminUsersUser(
    string $email,
    array $roles,
    string $firstname,
    string $lastname,
    DateTimeImmutable $createdAt,
    bool $isActive = true,
): User {
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive($isActive)
        ->setCreatedAt($createdAt);
}

function adminUsersRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
{
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    return Request::create('/admin/users', 'GET');
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var AdminController $controller */
$controller = $container->get(AdminController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface $authorizationChecker */
$authorizationChecker = $controllerContainer->get('security.authorization_checker');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var UserRepository $userRepository */
$userRepository = $entityManager->getRepository(User::class);

$connection->beginTransaction();
$testRequest = Request::create('/admin/users', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);
$testRequestPopped = false;

try {
    $admin = adminUsersUser('admin-users-admin@example.test', ['ROLE_ADMIN'], 'Admin', 'Test', new DateTimeImmutable('2099-11-01 10:00:00'));
    $client = adminUsersUser(
        'admin-users-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("firstname")</script>',
        'Client',
        new DateTimeImmutable('2099-11-05 10:00:00'),
    );
    $technician = adminUsersUser(
        'admin-users-tech@example.test',
        ['ROLE_TECHNICIAN'],
        'Tech',
        '<script>alert("lastname")</script>',
        new DateTimeImmutable('2099-11-04 10:00:00'),
        false,
    );
    $unknownRoleUser = adminUsersUser(
        '<script>alert("email")</script>@example.test',
        ['ROLE_SUPPORT_CUSTOM'],
        'Role',
        'Inconnu',
        new DateTimeImmutable('2099-11-03 10:00:00'),
    );
    $multiRoleUser = adminUsersUser(
        'admin-users-multi@example.test',
        ['ROLE_ADMIN', 'ROLE_CLIENT'],
        'Multi',
        'Role',
        new DateTimeImmutable('2099-11-02 10:00:00'),
    );

    foreach ([$admin, $client, $technician, $unknownRoleUser, $multiRoleUser] as $user) {
        $entityManager->persist($user);
    }
    $entityManager->flush();

    $tokenStorage->setToken(null);
    ensureAdminUsers(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'An unauthenticated user must not have ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    ensureAdminUsers(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_CLIENT must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($technician, 'main', $technician->getRoles()));
    ensureAdminUsers(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_TECHNICIAN must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    ensureAdminUsers($authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_ADMIN must grant access to /admin/users.');

    $adminResponse = $kernel->handle(adminUsersRequest($tokenStorage, $admin), HttpKernelInterface::SUB_REQUEST);
    ensureAdminUsers(Response::HTTP_OK === $adminResponse->getStatusCode(), sprintf('ROLE_ADMIN GET /admin/users must return 200 (got %d).', $adminResponse->getStatusCode()));

    $requestStack->pop();
    $testRequestPopped = true;

    $testRequest = Request::create('/admin/users', 'GET');
    $testRequest->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($testRequest);
    $testRequestPopped = false;
    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

    $users = $userRepository->findAllForAdminList();
    $firstFiveEmails = array_column(array_slice($users, 0, 5), 'email');
    ensureAdminUsers([
        'admin-users-client@example.test',
        'admin-users-tech@example.test',
        '<script>alert("email")</script>@example.test',
        'admin-users-multi@example.test',
        'admin-users-admin@example.test',
    ] === $firstFiveEmails, 'Admin users must be ordered by createdAt DESC then id DESC.');

    $clientRow = $users[0];
    $technicianRow = $users[1];
    $unknownRoleRow = $users[2];
    ensureAdminUsers(['ROLE_CLIENT'] === $clientRow['roles'], 'The client row must expose only persisted roles.');
    ensureAdminUsers(['ROLE_TECHNICIAN'] === $technicianRow['roles'], 'The technician row must expose only persisted roles.');
    ensureAdminUsers(!in_array('ROLE_USER', $clientRow['roles'], true), 'Automatic ROLE_USER must not be exposed when it is not persisted.');
    ensureAdminUsers(['ROLE_SUPPORT_CUSTOM'] === $unknownRoleRow['roles'], 'Unknown persisted roles must remain available for display.');

    $userCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM "user"');
    $response = $controller->users($userRepository);
    $html = $response->getContent();

    ensureAdminUsers(Response::HTTP_OK === $response->getStatusCode(), 'The admin users page must render with HTTP 200.');
    ensureAdminUsers(str_contains($html, 'Utilisateurs'), 'The users page title is missing.');
    ensureAdminUsers(str_contains($html, '#'.$client->getId()), 'User ids must be rendered.');
    ensureAdminUsers(str_contains($html, '&lt;script&gt;alert(&quot;firstname&quot;)&lt;/script&gt;'), 'Firstnames must be escaped.');
    ensureAdminUsers(str_contains($html, '&lt;script&gt;alert(&quot;lastname&quot;)&lt;/script&gt;'), 'Lastnames must be escaped.');
    ensureAdminUsers(str_contains($html, '&lt;script&gt;alert(&quot;email&quot;)&lt;/script&gt;@example.test'), 'Emails must be escaped.');
    ensureAdminUsers(str_contains($html, 'Client'), 'ROLE_CLIENT must be translated.');
    ensureAdminUsers(str_contains($html, 'Technicien'), 'ROLE_TECHNICIAN must be translated.');
    ensureAdminUsers(str_contains($html, 'Administrateur'), 'ROLE_ADMIN must be translated.');
    ensureAdminUsers(str_contains($html, 'ROLE_SUPPORT_CUSTOM'), 'Unknown persisted roles must be displayed raw and escaped.');
    ensureAdminUsers(!str_contains($html, 'ROLE_USER'), 'Automatic ROLE_USER must not be displayed.');
    ensureAdminUsers(str_contains($html, 'Actif'), 'Active users must display Actif.');
    ensureAdminUsers(str_contains($html, 'Inactif'), 'Inactive users must display Inactif.');
    ensureAdminUsers(str_contains($html, '05/11/2099 10:00'), 'Creation dates must use d/m/Y H:i.');
    ensureAdminUsers(!str_contains($html, '<form'), 'The admin users page must not render any form.');
    ensureAdminUsers(!str_contains($html, 'method="post"'), 'The admin users page must not render POST forms.');
    ensureAdminUsers(!str_contains($html, 'Modifier'), 'The admin users page must not expose edit actions.');
    ensureAdminUsers(!str_contains($html, 'Supprimer'), 'The admin users page must not expose delete actions.');
    ensureAdminUsers(!str_contains($html, 'Désactiver'), 'The admin users page must not expose disable actions.');
    ensureAdminUsers(!str_contains($html, 'Réinitialiser'), 'The admin users page must not expose password reset actions.');
    ensureAdminUsers($userCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM "user"'), 'Rendering admin users must not persist users.');

    $firstPosition = strpos($html, '&lt;script&gt;alert(&quot;firstname&quot;)&lt;/script&gt;');
    $secondPosition = strpos($html, '&lt;script&gt;alert(&quot;lastname&quot;)&lt;/script&gt;');
    $thirdPosition = strpos($html, '&lt;script&gt;alert(&quot;email&quot;)&lt;/script&gt;@example.test');
    ensureAdminUsers(
        false !== $firstPosition
        && false !== $secondPosition
        && false !== $thirdPosition
        && $firstPosition < $secondPosition
        && $secondPosition < $thirdPosition,
        'Rendered users must follow repository ordering.',
    );

    $emptyHtml = $twig->render('admin/users.html.twig', [
        'users' => [],
        'role_labels' => [
            'ROLE_CLIENT' => 'Client',
            'ROLE_TECHNICIAN' => 'Technicien',
            'ROLE_ADMIN' => 'Administrateur',
        ],
    ]);
    ensureAdminUsers(str_contains($emptyHtml, 'Aucun utilisateur à afficher.'), 'The empty state is missing.');

    echo "Admin users tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    if (!$testRequestPopped) {
        $requestStack->pop();
    }

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
