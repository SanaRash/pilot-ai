<?php

declare(strict_types=1);

use App\Controller\ClientController;
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
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAuthVisualConsistency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertNoSensitiveAuthFields(string $html, string $context): void
{
    foreach (['name="role"', 'name="roles"', '[roles]', 'ROLE_ADMIN', 'Administrateur'] as $forbidden) {
        ensureAuthVisualConsistency(
            !str_contains($html, $forbidden),
            sprintf('%s must not expose sensitive role/admin field or value: %s.', $context, $forbidden),
        );
    }
}

function assertAnonymousAuthPage(string $html, string $context): void
{
    ensureAuthVisualConsistency(str_contains($html, 'class="auth-page"'), sprintf('%s auth page wrapper is missing.', $context));
    ensureAuthVisualConsistency(str_contains($html, 'class="auth-card"'), sprintf('%s auth card is missing.', $context));
    ensureAuthVisualConsistency(!str_contains($html, 'Navigation principale'), sprintf('%s must not show role navigation to anonymous users.', $context));
    assertNoSensitiveAuthFields($html, $context);
}

function renderLoginWithError(
    Environment $twig,
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
): string {
    $tokenStorage->setToken(null);
    $request = Request::create('/login', 'GET');
    $request->attributes->set('_route', 'app_login');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($request);

    try {
        return $twig->render('security/login.html.twig', [
            'last_username' => 'bad-login@example.test',
            'error' => new BadCredentialsException('Invalid credentials.'),
        ]);
    } finally {
        $requestStack->pop();
    }
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
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');

try {
    $tokenStorage->setToken(null);

    $loginResponse = $kernel->handle(Request::create('/login', 'GET'), HttpKernelInterface::SUB_REQUEST);
    $loginHtml = (string) $loginResponse->getContent();
    ensureAuthVisualConsistency(Response::HTTP_OK === $loginResponse->getStatusCode(), 'GET /login must return 200.');
    assertAnonymousAuthPage($loginHtml, 'Login');
    ensureAuthVisualConsistency(str_contains($loginHtml, '<form class="auth-form" method="post">'), 'Login form must remain a POST form.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'name="_username"'), 'Login email/identifier field is missing.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'type="email"'), 'Login identifier field must remain an email input.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'name="_password"'), 'Login password field is missing.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'type="password"'), 'Login password input is missing.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'name="_csrf_token"'), 'Login CSRF token is missing.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'Mot de passe'), 'Login password label must be in French.');
    ensureAuthVisualConsistency(str_contains($loginHtml, 'Se connecter'), 'Login submit button is missing.');

    $loginErrorHtml = renderLoginWithError($twig, $requestStack, $tokenStorage);
    ensureAuthVisualConsistency(str_contains($loginErrorHtml, 'class="auth-error"'), 'Login authentication error must keep a visible auth-error block.');
    ensureAuthVisualConsistency(str_contains($loginErrorHtml, 'role="alert"'), 'Login authentication error must remain announced as an alert.');
    ensureAuthVisualConsistency(str_contains($loginErrorHtml, 'bad-login@example.test'), 'Login last username must still be rendered.');

    $registerResponse = $kernel->handle(Request::create('/register', 'GET'), HttpKernelInterface::SUB_REQUEST);
    $registerHtml = (string) $registerResponse->getContent();
    ensureAuthVisualConsistency(Response::HTTP_OK === $registerResponse->getStatusCode(), 'GET /register must return 200.');
    assertAnonymousAuthPage($registerHtml, 'Register');
    ensureAuthVisualConsistency(str_contains($registerHtml, '<form') && str_contains($registerHtml, 'class="auth-form"'), 'Register Symfony form must keep auth-form styling.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[firstname]"'), 'Register firstname field is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[lastname]"'), 'Register lastname field is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[email]"'), 'Register email field is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[plainPassword]"'), 'Register password field is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[agreeTerms]"'), 'Register agreeTerms field is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'name="registration_form[_token]"'), 'Register CSRF token is missing.');
    ensureAuthVisualConsistency(str_contains($registerHtml, 'Créer mon compte'), 'Register submit button is missing.');

    $baseTemplate = file_get_contents(dirname(__DIR__, 2).'/templates/base.html.twig');
    ensureAuthVisualConsistency(false !== $baseTemplate && str_contains($baseTemplate, '.auth-page'), 'Shared auth-page styles are missing.');
    ensureAuthVisualConsistency(false !== $baseTemplate && str_contains($baseTemplate, '.auth-card'), 'Shared auth-card styles are missing.');
    ensureAuthVisualConsistency(false !== $baseTemplate && !str_contains($baseTemplate, 'ROLE_ADMIN'), 'Base auth styles must not introduce role values.');

    $securityConfig = file_get_contents(dirname(__DIR__, 2).'/config/packages/security.yaml');
    ensureAuthVisualConsistency(false !== $securityConfig && str_contains($securityConfig, 'form_login:'), 'Security form_login configuration must remain present.');

    echo "Auth visual consistency tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $kernel->shutdown();
}
