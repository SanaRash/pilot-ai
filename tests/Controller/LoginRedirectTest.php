<?php

declare(strict_types=1);

use App\Entity\User;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureLoginRedirect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function loginRedirectUser(
    string $email,
    array $roles,
    string $plainPassword = 'CorrectHorseBatteryStaple123!',
): User {
    $user = (new User())
        ->setEmail($email)
        ->setFirstname('Login')
        ->setLastname('Redirect')
        ->setRoles($roles)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));

    $user->setPassword(password_hash($plainPassword, PASSWORD_DEFAULT));

    return $user;
}

function loginRedirectRequest(
    string $email,
    string $password,
    string $csrfToken,
    Session $session,
): Request {
    $request = Request::create('/login', 'POST', [
        '_username' => $email,
        '_password' => $password,
        '_csrf_token' => $csrfToken,
    ], [], [], [
        'HTTP_ORIGIN' => 'http://localhost',
    ]);
    $request->setSession($session);

    return $request;
}

function loginRedirectCsrfToken(Kernel $kernel, Session $session): string
{
    $request = Request::create('/login', 'GET');
    $request->setSession($session);
    $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);

    ensureLoginRedirect(Response::HTTP_OK === $response->getStatusCode(), 'GET /login must render before submitting credentials.');
    preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $response->getContent(), $matches);
    ensureLoginRedirect(isset($matches[1]) && '' !== $matches[1], 'Login CSRF token must be rendered.');

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assertLoginRedirectsTo(
    Kernel $kernel,
    string $email,
    string $expectedPath,
    ?Session $session = null,
): Session {
    $session ??= new Session(new MockArraySessionStorage());
    $csrfToken = loginRedirectCsrfToken($kernel, $session);

    $response = $kernel->handle(loginRedirectRequest(
        $email,
        'CorrectHorseBatteryStaple123!',
        $csrfToken,
        $session,
    ), HttpKernelInterface::MAIN_REQUEST);

    ensureLoginRedirect(Response::HTTP_FOUND === $response->getStatusCode(), sprintf(
        'A successful login for %s must redirect, got HTTP %d.',
        $email,
        $response->getStatusCode(),
    ));
    ensureLoginRedirect($expectedPath === $response->headers->get('Location'), sprintf(
        'A successful login for %s must redirect to %s, got %s.',
        $email,
        $expectedPath,
        (string) $response->headers->get('Location'),
    ));

    return $session;
}

function assertLoginRedirectRoleDenied(
    TokenStorageInterface $tokenStorage,
    AuthorizationCheckerInterface $authorizationChecker,
    User $user,
    string $role,
    string $path,
): void {
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    ensureLoginRedirect(!$authorizationChecker->isGranted($role), sprintf(
        'The authenticated user must not have %s, required by %s.',
        $role,
        $path,
    ));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var App\Controller\ClientController $clientController */
$clientController = $container->get(App\Controller\ClientController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($clientController);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var AuthorizationCheckerInterface $authorizationChecker */
$authorizationChecker = $controllerContainer->get('security.authorization_checker');
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$connection->beginTransaction();

try {
    $securityConfig = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
    ensureLoginRedirect([
        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/technician', 'roles' => 'ROLE_TECHNICIAN'],
        ['path' => '^/client', 'roles' => 'ROLE_CLIENT'],
    ] === ($securityConfig['security']['access_control'] ?? null), 'The access_control role rules or order changed.');

    $clientUser = loginRedirectUser('login-client@example.test', ['ROLE_CLIENT']);
    $technicianUser = loginRedirectUser('login-technician@example.test', ['ROLE_TECHNICIAN']);
    $adminUser = loginRedirectUser('login-admin@example.test', ['ROLE_ADMIN']);
    $technicianClientUser = loginRedirectUser('login-tech-client@example.test', ['ROLE_TECHNICIAN', 'ROLE_CLIENT']);
    $adminTechnicianClientUser = loginRedirectUser('login-admin-tech-client@example.test', ['ROLE_ADMIN', 'ROLE_TECHNICIAN', 'ROLE_CLIENT']);
    $rolelessUser = loginRedirectUser('login-roleless@example.test', []);

    foreach ([
        $clientUser,
        $technicianUser,
        $adminUser,
        $technicianClientUser,
        $adminTechnicianClientUser,
        $rolelessUser,
    ] as $user) {
        $entityManager->persist($user);
    }

    $entityManager->flush();

    assertLoginRedirectsTo($kernel, $clientUser->getEmail(), '/client');
    assertLoginRedirectsTo($kernel, $technicianUser->getEmail(), '/technician');
    assertLoginRedirectsTo($kernel, $adminUser->getEmail(), '/admin');
    assertLoginRedirectsTo($kernel, $technicianClientUser->getEmail(), '/technician');
    assertLoginRedirectsTo($kernel, $adminTechnicianClientUser->getEmail(), '/admin');
    assertLoginRedirectsTo($kernel, $rolelessUser->getEmail(), '/login');

    assertLoginRedirectRoleDenied($tokenStorage, $authorizationChecker, $clientUser, 'ROLE_ADMIN', '/admin');
    assertLoginRedirectRoleDenied($tokenStorage, $authorizationChecker, $clientUser, 'ROLE_TECHNICIAN', '/technician');
    assertLoginRedirectRoleDenied($tokenStorage, $authorizationChecker, $technicianUser, 'ROLE_ADMIN', '/admin');

    $invalidSession = new Session(new MockArraySessionStorage());
    $invalidResponse = $kernel->handle(loginRedirectRequest(
        $clientUser->getEmail(),
        'wrong-password',
        loginRedirectCsrfToken($kernel, $invalidSession),
        $invalidSession,
    ), HttpKernelInterface::MAIN_REQUEST);
    ensureLoginRedirect(Response::HTTP_FOUND === $invalidResponse->getStatusCode(), 'Invalid credentials must redirect back to login.');
    ensureLoginRedirect('/login' === parse_url((string) $invalidResponse->headers->get('Location'), PHP_URL_PATH), 'Invalid credentials must not reach a protected dashboard.');

    $invalidCsrfResponse = $kernel->handle(loginRedirectRequest(
        $clientUser->getEmail(),
        'CorrectHorseBatteryStaple123!',
        'invalid-csrf-token',
        new Session(new MockArraySessionStorage()),
    ), HttpKernelInterface::MAIN_REQUEST);
    ensureLoginRedirect(Response::HTTP_FOUND === $invalidCsrfResponse->getStatusCode(), 'Invalid CSRF must redirect back to login.');
    ensureLoginRedirect('/login' === parse_url((string) $invalidCsrfResponse->headers->get('Location'), PHP_URL_PATH), 'Invalid CSRF must not reach a protected dashboard.');

    $targetPathSession = new Session(new MockArraySessionStorage());
    $adminAttemptRequest = Request::create('/admin', 'GET');
    $adminAttemptRequest->setSession($targetPathSession);
    $adminAttemptResponse = $kernel->handle($adminAttemptRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureLoginRedirect(Response::HTTP_FOUND === $adminAttemptResponse->getStatusCode(), 'Unauthenticated protected access must redirect to login.');
    ensureLoginRedirect(str_contains((string) $adminAttemptResponse->headers->get('Location'), '/login'), 'Unauthenticated protected access must target login.');
    assertLoginRedirectsTo($kernel, $clientUser->getEmail(), '/client', $targetPathSession);

    echo "Login redirect tests: PASS
";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
