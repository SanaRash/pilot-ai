<?php

declare(strict_types=1);

use App\Entity\User;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureLogoutRedirect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$connection->beginTransaction();

try {
    $securityConfig = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
    ensureLogoutRedirect([
        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/technician', 'roles' => 'ROLE_TECHNICIAN'],
        ['path' => '^/client', 'roles' => 'ROLE_CLIENT'],
    ] === ($securityConfig['security']['access_control'] ?? null), 'The access_control rules or order changed.');

    $plainPassword = 'CorrectHorseBatteryStaple123!';
    $user = (new User())
        ->setEmail('logout-redirect@example.test')
        ->setFirstname('Logout')
        ->setLastname('Redirect')
        ->setRoles(['ROLE_CLIENT'])
        ->setPassword(password_hash($plainPassword, PASSWORD_DEFAULT))
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
    $entityManager->persist($user);
    $entityManager->flush();

    $session = new Session(new MockArraySessionStorage());
    $loginRequest = Request::create('/login', 'GET');
    $loginRequest->setSession($session);
    $loginPage = $kernel->handle($loginRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureLogoutRedirect(Response::HTTP_OK === $loginPage->getStatusCode(), 'GET /login must render before login.');
    preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $loginPage->getContent(), $matches);
    ensureLogoutRedirect(isset($matches[1]) && '' !== $matches[1], 'The login CSRF token must be rendered.');

    $loginRequest = Request::create('/login', 'POST', [
        '_username' => $user->getEmail(),
        '_password' => $plainPassword,
        '_csrf_token' => html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ], [], [], ['HTTP_ORIGIN' => 'http://localhost']);
    $loginRequest->setSession($session);
    $loginResponse = $kernel->handle($loginRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureLogoutRedirect(Response::HTTP_FOUND === $loginResponse->getStatusCode(), 'The valid client login must redirect.');
    ensureLogoutRedirect('/client' === parse_url((string) $loginResponse->headers->get('Location'), PHP_URL_PATH), 'The test user must be authenticated before logout.');
    ensureLogoutRedirect($session->has('_security_main'), 'Successful login must store an authenticated session.');

    $logoutRequest = Request::create('/logout', 'GET');
    $logoutRequest->setSession($session);
    $logoutResponse = $kernel->handle($logoutRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureLogoutRedirect(Response::HTTP_FOUND === $logoutResponse->getStatusCode(), 'GET /logout must redirect after logout.');
    ensureLogoutRedirect('/login' === parse_url((string) $logoutResponse->headers->get('Location'), PHP_URL_PATH), 'Logout must redirect to /login.');
    ensureLogoutRedirect(!$session->has('_security_main'), 'Logout must remove the authenticated security token from the session.');

    $protectedRequest = Request::create('/client', 'GET');
    $protectedRequest->setSession($session);
    $protectedResponse = $kernel->handle($protectedRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureLogoutRedirect(Response::HTTP_FOUND === $protectedResponse->getStatusCode(), 'The logged-out user must be redirected away from /client.');
    ensureLogoutRedirect('/login' === parse_url((string) $protectedResponse->headers->get('Location'), PHP_URL_PATH), 'Protected access after logout must redirect to /login.');

    echo "Logout redirect tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
