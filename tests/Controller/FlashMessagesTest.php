<?php

declare(strict_types=1);

use App\Controller\ClientController;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureFlashMessages(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function flashMessagesUser(array $roles = ['ROLE_CLIENT']): User
{
    return (new User())
        ->setEmail('flash-messages-'.sha1(implode('-', $roles)).'@example.test')
        ->setFirstname('Flash')
        ->setLastname('Messages')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

/**
 * @param array<string, list<string>> $flashes
 */
function renderFlashMessages(
    Environment $twig,
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
    array $flashes,
    string $template = "{% extends 'base.html.twig' %}{% block title %}Flash test{% endblock %}{% block body %}<main>Flash test</main>{% endblock %}",
    array $context = [],
): string {
    $user = flashMessagesUser();
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    $request = Request::create('/client/tickets', 'GET');
    $request->attributes->set('_route', 'app_client_tickets');
    $session = new Session(new MockArraySessionStorage());

    foreach ($flashes as $type => $messages) {
        foreach ($messages as $message) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    $request->setSession($session);
    $requestStack->push($request);

    try {
        return str_starts_with($template, '@')
            ? $twig->render(substr($template, 1), $context)
            : $twig->createTemplate($template)->render($context);
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
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');

try {
    $emptyHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, []);
    ensureFlashMessages(!str_contains($emptyHtml, 'class="flash-messages"'), 'No flash container must be rendered when there is no message.');

    $successHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'success' => ['Succès <script>alert("flash")</script>'],
    ]);
    ensureFlashMessages(str_contains($successHtml, 'flash-messages__message--success'), 'Success flash style is missing.');
    ensureFlashMessages(str_contains($successHtml, 'role="status"'), 'Success flash must use role=status.');
    ensureFlashMessages(str_contains($successHtml, 'aria-live="polite"'), 'Success flash must use aria-live=polite.');
    ensureFlashMessages(str_contains($successHtml, 'Succès &lt;script&gt;alert(&quot;flash&quot;)&lt;/script&gt;'), 'Success flash must be escaped.');
    ensureFlashMessages(!str_contains($successHtml, '<script>alert("flash")</script>'), 'Success flash must not render raw HTML.');

    $infoHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'info' => ['Information <script>alert("info")</script>'],
    ]);
    ensureFlashMessages(str_contains($infoHtml, 'flash-messages__message--info'), 'Info flash style is missing.');
    ensureFlashMessages(str_contains($infoHtml, 'role="status"'), 'Info flash must use role=status.');
    ensureFlashMessages(str_contains($infoHtml, 'Information &lt;script&gt;alert(&quot;info&quot;)&lt;/script&gt;'), 'Info flash must be escaped.');

    $warningHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'warning' => ['Attention <script>alert("warning")</script>'],
    ]);
    ensureFlashMessages(str_contains($warningHtml, 'flash-messages__message--warning'), 'Warning flash style is missing.');
    ensureFlashMessages(str_contains($warningHtml, 'role="alert"'), 'Warning flash must use role=alert.');
    ensureFlashMessages(str_contains($warningHtml, 'aria-live="assertive"'), 'Warning flash must use aria-live=assertive.');
    ensureFlashMessages(str_contains($warningHtml, 'Attention &lt;script&gt;alert(&quot;warning&quot;)&lt;/script&gt;'), 'Warning flash must be escaped.');

    $errorHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'error' => ['Erreur <script>alert("error")</script>'],
    ]);
    ensureFlashMessages(str_contains($errorHtml, 'flash-messages__message--error'), 'Error flash style is missing.');
    ensureFlashMessages(str_contains($errorHtml, 'role="alert"'), 'Error flash must use role=alert.');
    ensureFlashMessages(str_contains($errorHtml, 'Erreur &lt;script&gt;alert(&quot;error&quot;)&lt;/script&gt;'), 'Error flash must be escaped.');

    $dangerHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'danger' => ['Danger'],
    ]);
    ensureFlashMessages(str_contains($dangerHtml, 'flash-messages__message--danger'), 'Danger flash must keep a danger/error visual class.');
    ensureFlashMessages(str_contains($dangerHtml, 'role="alert"'), 'Danger flash must use role=alert.');

    $unknownHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'custom' => ['Message inconnu'],
    ]);
    ensureFlashMessages(str_contains($unknownHtml, 'Message inconnu'), 'Unknown flash types must still be rendered.');
    ensureFlashMessages(str_contains($unknownHtml, 'flash-messages__message--info'), 'Unknown flash types must use the neutral info style.');

    $multipleHtml = renderFlashMessages($twig, $requestStack, $tokenStorage, [
        'success' => ['Premier message', 'Deuxième message'],
    ]);
    ensureFlashMessages(str_contains($multipleHtml, 'Premier message'), 'First flash message is missing.');
    ensureFlashMessages(str_contains($multipleHtml, 'Deuxième message'), 'Second flash message is missing.');
    ensureFlashMessages(strpos($multipleHtml, 'Premier message') < strpos($multipleHtml, 'Deuxième message'), 'Multiple messages of the same type must keep their order.');

    $clientTicketsHtml = renderFlashMessages(
        $twig,
        $requestStack,
        $tokenStorage,
        ['success' => ['Votre demande a bien été envoyée.']],
        '@client_ticket/tickets.html.twig',
        [
            'tickets' => [],
            'status_labels' => [
                Ticket::STATUS_OPEN => 'Ouvert',
                Ticket::STATUS_IN_PROGRESS => 'En cours',
                Ticket::STATUS_RESOLVED => 'Résolu',
                Ticket::STATUS_CLOSED => 'Fermé',
            ],
        ],
    );
    ensureFlashMessages(str_contains($clientTicketsHtml, 'Votre demande a bien été envoyée.'), 'Client success flash must be visible on the redirected ticket list page.');

    $clientNewTemplate = file_get_contents(dirname(__DIR__, 2).'/templates/client_ticket/new.html.twig');
    $technicianShowTemplate = file_get_contents(dirname(__DIR__, 2).'/templates/technician/ticket_show.html.twig');
    ensureFlashMessages(is_string($clientNewTemplate) && !str_contains($clientNewTemplate, 'app.flashes'), 'Client new template must not consume flashes locally.');
    ensureFlashMessages(is_string($technicianShowTemplate) && !str_contains($technicianShowTemplate, 'app.flashes'), 'Technician ticket show template must not consume flashes locally.');
    ensureFlashMessages(is_string($technicianShowTemplate) && !str_contains($technicianShowTemplate, 'technician-ticket-detail__flash'), 'Technician local flash markup/styles must be removed to avoid duplication.');

    echo "Flash messages tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $kernel->shutdown();
}
