<?php

declare(strict_types=1);

use App\Controller\ClientController;
use App\Entity\User;
use App\Kernel;
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

function ensureRoleNavigation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function roleNavigationUser(array $roles): User
{
    return (new User())
        ->setEmail('role-navigation-'.sha1(implode('-', $roles)).'@example.test')
        ->setFirstname('Role')
        ->setLastname('Navigation')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function renderRoleNavigation(
    Environment $twig,
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
    ?User $user,
    string $routeName = 'app_client',
    string $path = '/client',
): string {
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    $request = Request::create($path, 'GET');
    $request->attributes->set('_route', $routeName);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($request);

    try {
        return $twig
            ->createTemplate("{% extends 'base.html.twig' %}{% block title %}Navigation test{% endblock %}{% block body %}<main>Navigation test</main>{% endblock %}")
            ->render();
    } finally {
        $requestStack->pop();
    }
}

function ensureRoleNavigationLink(string $html, string $href, string $label): void
{
    ensureRoleNavigation(
        str_contains($html, sprintf('href="%s"', $href)),
        sprintf('The "%s" link to "%s" is missing.', $label, $href),
    );
}

function ensureRoleNavigationNoLink(string $html, string $href, string $label): void
{
    ensureRoleNavigation(
        !str_contains($html, sprintf('href="%s"', $href)),
        sprintf('The "%s" link to "%s" must not be rendered.', $label, $href),
    );
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var ClientController $controller */
$controller = $container->get(ClientController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');

try {
    $anonymousHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, null);
    ensureRoleNavigation(!str_contains($anonymousHtml, 'Navigation principale'), 'Anonymous rendering must not show the role navigation.');
    ensureRoleNavigationNoLink($anonymousHtml, '/client', 'Client dashboard');
    ensureRoleNavigationNoLink($anonymousHtml, '/technician', 'Technician dashboard');
    ensureRoleNavigationNoLink($anonymousHtml, '/admin', 'Admin dashboard');

    $tokenStorage->setToken(null);
    $loginResponse = $kernel->handle(Request::create('/login', 'GET'), HttpKernelInterface::SUB_REQUEST);
    ensureRoleNavigation(Response::HTTP_OK === $loginResponse->getStatusCode(), 'Login page must render for anonymous users.');
    ensureRoleNavigation(!str_contains($loginResponse->getContent(), 'Navigation principale'), 'Login page must not show role navigation to anonymous users.');

    $registerResponse = $kernel->handle(Request::create('/register', 'GET'), HttpKernelInterface::SUB_REQUEST);
    ensureRoleNavigation(Response::HTTP_OK === $registerResponse->getStatusCode(), 'Register page must render for anonymous users.');
    ensureRoleNavigation(!str_contains($registerResponse->getContent(), 'Navigation principale'), 'Register page must not show role navigation to anonymous users.');

    $clientHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, roleNavigationUser(['ROLE_CLIENT']), 'app_client_tickets', '/client/tickets');
    ensureRoleNavigation(str_contains($clientHtml, 'Espace client'), 'Client navigation group is missing.');
    ensureRoleNavigationLink($clientHtml, '/client', 'Client dashboard');
    ensureRoleNavigationLink($clientHtml, '/client/tickets', 'Client tickets');
    ensureRoleNavigationLink($clientHtml, '/client/ticket/new', 'New client ticket');
    ensureRoleNavigationLink($clientHtml, '/logout', 'Logout');
    ensureRoleNavigation(str_contains($clientHtml, 'href="/client/tickets" aria-current="page"'), 'The exact current client route must be marked active.');
    ensureRoleNavigation(!str_contains($clientHtml, 'Espace technicien'), 'Technician group must not be visible to a client-only user.');
    ensureRoleNavigation(!str_contains($clientHtml, 'Espace admin'), 'Admin group must not be visible to a client-only user.');
    ensureRoleNavigationNoLink($clientHtml, '/technician', 'Technician dashboard');
    ensureRoleNavigationNoLink($clientHtml, '/admin', 'Admin dashboard');

    $technicianHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, roleNavigationUser(['ROLE_TECHNICIAN']), 'app_technician', '/technician');
    ensureRoleNavigation(str_contains($technicianHtml, 'Espace technicien'), 'Technician navigation group is missing.');
    ensureRoleNavigationLink($technicianHtml, '/technician', 'Technician dashboard');
    ensureRoleNavigationLink($technicianHtml, '/technician/tickets', 'Open tickets');
    ensureRoleNavigationLink($technicianHtml, '/technician/tickets/assigned', 'Assigned tickets');
    ensureRoleNavigationLink($technicianHtml, '/logout', 'Logout');
    ensureRoleNavigation(str_contains($technicianHtml, 'href="/technician" aria-current="page"'), 'The exact current technician route must be marked active.');
    ensureRoleNavigation(!str_contains($technicianHtml, 'Espace client'), 'Client group must not be visible to a technician-only user.');
    ensureRoleNavigation(!str_contains($technicianHtml, 'Espace admin'), 'Admin group must not be visible to a technician-only user.');
    ensureRoleNavigationNoLink($technicianHtml, '/client', 'Client dashboard');
    ensureRoleNavigationNoLink($technicianHtml, '/admin', 'Admin dashboard');

    $adminHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, roleNavigationUser(['ROLE_ADMIN']), 'app_admin_statistics', '/admin/statistics');
    ensureRoleNavigation(str_contains($adminHtml, 'Espace admin'), 'Admin navigation group is missing.');
    ensureRoleNavigationLink($adminHtml, '/admin', 'Admin dashboard');
    ensureRoleNavigationLink($adminHtml, '/admin/users', 'Admin users');
    ensureRoleNavigationLink($adminHtml, '/admin/categories', 'Admin categories');
    ensureRoleNavigationLink($adminHtml, '/admin/statistics', 'Admin statistics');
    ensureRoleNavigationLink($adminHtml, '/logout', 'Logout');
    ensureRoleNavigation(str_contains($adminHtml, 'href="/admin/statistics" aria-current="page"'), 'The exact current admin route must be marked active.');
    ensureRoleNavigation(!str_contains($adminHtml, 'Espace client'), 'Client group must not be visible to an admin-only user.');
    ensureRoleNavigation(!str_contains($adminHtml, 'Espace technicien'), 'Technician group must not be visible to an admin-only user.');
    ensureRoleNavigationNoLink($adminHtml, '/client', 'Client dashboard');
    ensureRoleNavigationNoLink($adminHtml, '/technician', 'Technician dashboard');

    $clientTechnicianHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, roleNavigationUser(['ROLE_CLIENT', 'ROLE_TECHNICIAN']));
    ensureRoleNavigation(str_contains($clientTechnicianHtml, 'Espace client'), 'Client group must be visible for a client+technician user.');
    ensureRoleNavigation(str_contains($clientTechnicianHtml, 'Espace technicien'), 'Technician group must be visible for a client+technician user.');
    ensureRoleNavigation(!str_contains($clientTechnicianHtml, 'Espace admin'), 'Admin group must not be visible without ROLE_ADMIN.');

    $adminTechnicianHtml = renderRoleNavigation($twig, $requestStack, $tokenStorage, roleNavigationUser(['ROLE_ADMIN', 'ROLE_TECHNICIAN']));
    ensureRoleNavigation(str_contains($adminTechnicianHtml, 'Espace admin'), 'Admin group must be visible for an admin+technician user.');
    ensureRoleNavigation(str_contains($adminTechnicianHtml, 'Espace technicien'), 'Technician group must be visible for an admin+technician user.');
    ensureRoleNavigation(!str_contains($adminTechnicianHtml, 'Espace client'), 'Client group must not be visible without ROLE_CLIENT.');

    echo "Role navigation tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $kernel->shutdown();
}
