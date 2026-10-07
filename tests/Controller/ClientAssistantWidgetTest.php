<?php

declare(strict_types=1);

use App\Entity\User;
use App\Kernel;
use App\Controller\ClientController;
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

function ensureClientAssistantWidget(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientAssistantWidgetUser(array $roles): User
{
    return (new User())
        ->setEmail('assistant-widget-'.sha1(implode('-', $roles)).'@example.test')
        ->setFirstname('Widget')
        ->setLastname('Test')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable());
}

function renderClientAssistantWidget(
    Environment $twig,
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
    ?User $user,
    string $path,
    string $route,
    array $attributes = [],
): string {
    $tokenStorage->setToken(null === $user ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()));
    $request = Request::create($path, 'GET');
    $request->attributes->set('_route', $route);
    foreach ($attributes as $key => $value) {
        $request->attributes->set($key, $value);
    }
    $request->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($request);

    try {
        return $twig
            ->createTemplate("{% extends 'base.html.twig' %}{% block title %}Assistant widget test{% endblock %}{% block body %}<main>Assistant widget test</main>{% endblock %}")
            ->render();
    } finally {
        $requestStack->pop();
    }
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var ClientController $clientController */
$clientController = $container->get(ClientController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($clientController);
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');

try {
    $clientHtml = renderClientAssistantWidget($twig, $requestStack, $tokenStorage, clientAssistantWidgetUser(['ROLE_CLIENT']), '/client', 'app_client');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-client-assistant'), 'The assistant widget must be visible on client pages.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-endpoint="/client/assistant/message"'), 'The assistant widget endpoint is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-draft-endpoint="/client/assistant/ticket-draft"'), 'The assistant widget draft endpoint is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-csrf-token='), 'The assistant widget CSRF token is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'Pilot AI'), 'The assistant widget header is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'Assistant support'), 'The assistant widget subtitle is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-assistant-welcome-card'), 'The assistant welcome card must be visible on client pages.');
    ensureClientAssistantWidget(str_contains($clientHtml, '/images/assistant/pilot-ai-assistant.png'), 'The assistant welcome card must reference a local robot image.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'alt="Robot assistant Pilot AI"'), 'The assistant welcome image must provide descriptive alt text.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'Vous avez besoin'), 'The assistant welcome card copy is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'Fermer le message'), 'The assistant welcome card close button is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'js/client-assistant.js'), 'The assistant widget JavaScript asset is missing.');
    ensureClientAssistantWidget(str_contains($clientHtml, 'data-assistant-toggle'), 'The assistant bubble must remain visible.');
    ensureClientAssistantWidget(!str_contains($clientHtml, 'OPENROUTER'), 'The assistant widget must not expose OpenRouter configuration.');
    ensureClientAssistantWidget(!str_contains($clientHtml, 'Authorization'), 'The assistant widget must not expose provider authorization headers.');
    ensureClientAssistantWidget(!str_contains($clientHtml, 'api_key'), 'The assistant widget must not expose API keys.');

    $clientTicketHtml = renderClientAssistantWidget(
        $twig,
        $requestStack,
        $tokenStorage,
        clientAssistantWidgetUser(['ROLE_CLIENT']),
        '/client/tickets/123',
        'app_client_ticket_show',
        ['id' => 123],
    );
    ensureClientAssistantWidget(str_contains($clientTicketHtml, 'data-ticket-id="123"'), 'The assistant widget must expose owned ticket context on client ticket detail pages.');

    foreach ([
        ['path' => '/technician', 'route' => 'app_technician', 'roles' => ['ROLE_TECHNICIAN']],
        ['path' => '/admin', 'route' => 'app_admin', 'roles' => ['ROLE_ADMIN']],
        ['path' => '/login', 'route' => 'app_login', 'roles' => null],
        ['path' => '/register', 'route' => 'app_register', 'roles' => null],
    ] as $case) {
        $html = renderClientAssistantWidget(
            $twig,
            $requestStack,
            $tokenStorage,
            null === $case['roles'] ? null : clientAssistantWidgetUser($case['roles']),
            $case['path'],
            $case['route'],
        );
        ensureClientAssistantWidget(!str_contains($html, 'data-client-assistant'), sprintf('The assistant widget must not be visible on %s.', $case['path']));
        ensureClientAssistantWidget(!str_contains($html, 'data-assistant-welcome-card'), sprintf('The assistant welcome card must not be visible on %s.', $case['path']));
    }

    $widgetTemplate = file_get_contents(dirname(__DIR__, 2).'/templates/_client_assistant_widget.html.twig');
    $widgetScript = file_get_contents(dirname(__DIR__, 2).'/public/js/client-assistant.js');
    ensureClientAssistantWidget(is_string($widgetTemplate), 'The assistant widget template must be readable.');
    ensureClientAssistantWidget(is_string($widgetScript), 'The assistant widget script must be readable.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'fetch(widget.dataset.endpoint'), 'The assistant must submit messages with fetch.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'buildShortHistory'), 'The assistant widget must build a short conversation history.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'maxHistoryMessages = 6'), 'The assistant widget must keep at most six messages for backend context.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'normalizeHistoryItems'), 'The assistant widget must normalize restored history before rendering it.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'pushMemoryMessage'), 'The assistant widget must centralize memory insertion.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'previous.role === message.role && previous.content === content'), 'The assistant widget must reject consecutive duplicate messages before rendering.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'if (userMessageAdded) {'), 'The assistant widget must render a submitted user message only when it was actually added to memory.');
    ensureClientAssistantWidget(str_contains($widgetScript, "['user', 'assistant'].includes(item.role)"), 'The assistant widget must keep only backend-approved history roles.');
    ensureClientAssistantWidget(str_contains($widgetScript, "const toVisualAuthor = (role) => role === 'user' ? 'CLIENT' : 'BOT'"), 'The assistant widget must map backend history roles to the existing visual authors.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'history: shortHistory'), 'The assistant widget must send the short history payload to the backend.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'appendDraftButton'), 'The assistant widget must be able to render a ticket creation draft action.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'result.needsTechnician === true'), 'The ticket draft action must be shown only when needsTechnician=true.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'Créer une demande'), 'The ticket draft action label is missing.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'widget.dataset.draftEndpoint'), 'The ticket draft action must call the dedicated draft endpoint.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'question, answer, history'), 'The ticket draft action must send only the minimal assistant draft payload.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'window.location.assign'), 'A successful assistant draft must redirect to the existing ticket form.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'Pilot AI réfléchit…'), 'The assistant loading state is missing.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'Pilot AI n’est pas disponible pour le moment.'), 'The assistant provider error state is missing.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'pilotAiAssistantWelcomeDismissed'), 'The welcome card must use session storage to avoid repeated display.');
    ensureClientAssistantWidget(str_contains($widgetScript, "window.sessionStorage.setItem(welcomeStorageKey, '1')"), 'The welcome close action must persist dismissal in session storage.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'dismissWelcome();'), 'Opening the chat must hide the welcome card.');
    ensureClientAssistantWidget(str_contains($widgetScript, "welcomeClose?.addEventListener('click', dismissWelcome)"), 'The welcome close button must dismiss the welcome card.');
    ensureClientAssistantWidget(str_contains($widgetScript, 'textContent'), 'Assistant messages must be inserted as textContent.');
    ensureClientAssistantWidget(!str_contains($widgetTemplate, '|raw'), 'The assistant widget template must not render raw content.');
    ensureClientAssistantWidget(!str_contains($widgetScript, 'innerHTML'), 'The assistant widget script must not use innerHTML.');

    echo "ClientAssistantWidgetTest passed.\n";
} finally {
    $tokenStorage->setToken(null);
    $kernel->shutdown();
}
