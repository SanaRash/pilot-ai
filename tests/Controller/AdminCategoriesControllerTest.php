<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\CategoryRepository;
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

function ensureAdminCategories(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminCategoriesUser(string $email, array $roles, string $firstname = 'Admin'): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname('Test')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function adminCategoriesTicket(User $client, string $title, Category $category): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description non affichée sur la liste des catégories')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2099-12-01 10:00:00'))
        ->setCreatedBy($client)
        ->setCategory($category);
}

function adminCategoriesRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
{
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    return Request::create('/admin/categories', 'GET');
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
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);

$connection->beginTransaction();
$testRequest = Request::create('/admin/categories', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);
$testRequestPopped = false;

try {
    $admin = adminCategoriesUser('admin-categories-admin@example.test', ['ROLE_ADMIN']);
    $clientRole = adminCategoriesUser('admin-categories-client-role@example.test', ['ROLE_CLIENT'], 'ClientRole');
    $technicianRole = adminCategoriesUser('admin-categories-tech-role@example.test', ['ROLE_TECHNICIAN'], 'TechRole');
    $client = adminCategoriesUser('admin-categories-client@example.test', ['ROLE_CLIENT'], 'Client');

    $categoryWithTwoTickets = (new Category())->setName('000 Catégorie identique');
    $categoryWithOneTicket = (new Category())->setName('000 Catégorie identique');
    $categoryWithoutTicket = (new Category())->setName('001 <script>alert("category")</script>');

    foreach ([$admin, $clientRole, $technicianRole, $client, $categoryWithTwoTickets, $categoryWithOneTicket, $categoryWithoutTicket] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $firstTicket = adminCategoriesTicket($client, 'Ticket catégorie 1', $categoryWithTwoTickets);
    $secondTicket = adminCategoriesTicket($client, 'Ticket catégorie 2', $categoryWithTwoTickets);
    $thirdTicket = adminCategoriesTicket($client, 'Ticket catégorie 3', $categoryWithOneTicket);

    foreach ([$firstTicket, $secondTicket, $thirdTicket] as $ticket) {
        $entityManager->persist($ticket);
    }
    $entityManager->flush();

    $tokenStorage->setToken(null);
    ensureAdminCategories(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'An unauthenticated user must not have ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($clientRole, 'main', $clientRole->getRoles()));
    ensureAdminCategories(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_CLIENT must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($technicianRole, 'main', $technicianRole->getRoles()));
    ensureAdminCategories(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_TECHNICIAN must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    ensureAdminCategories($authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_ADMIN must grant access to /admin/categories.');

    $adminResponse = $kernel->handle(adminCategoriesRequest($tokenStorage, $admin), HttpKernelInterface::SUB_REQUEST);
    ensureAdminCategories(Response::HTTP_OK === $adminResponse->getStatusCode(), sprintf('ROLE_ADMIN GET /admin/categories must return 200 (got %d).', $adminResponse->getStatusCode()));

    $rows = $categoryRepository->findAllWithTicketCountForAdmin();
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[$row['id']] = $row;
    }

    ensureAdminCategories(2 === $rowsById[$categoryWithTwoTickets->getId()]['ticketCount'], 'Category with two tickets must display count 2.');
    ensureAdminCategories(1 === $rowsById[$categoryWithOneTicket->getId()]['ticketCount'], 'Category with one ticket must display count 1.');
    ensureAdminCategories(0 === $rowsById[$categoryWithoutTicket->getId()]['ticketCount'], 'Category without ticket must display count 0.');

    $orderedIds = array_column($rows, 'id');
    $firstPosition = array_search($categoryWithTwoTickets->getId(), $orderedIds, true);
    $secondPosition = array_search($categoryWithOneTicket->getId(), $orderedIds, true);
    $thirdPosition = array_search($categoryWithoutTicket->getId(), $orderedIds, true);
    ensureAdminCategories(
        false !== $firstPosition
        && false !== $secondPosition
        && false !== $thirdPosition
        && $firstPosition < $secondPosition
        && $secondPosition < $thirdPosition,
        'Categories must be ordered by name ASC then id ASC.',
    );

    $categoryCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM category');
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');

    $response = $controller->categories($categoryRepository);
    $html = $response->getContent();

    ensureAdminCategories(Response::HTTP_OK === $response->getStatusCode(), 'The admin categories page must render with HTTP 200.');
    ensureAdminCategories(str_contains($html, 'Catégories'), 'The categories page title is missing.');
    ensureAdminCategories(str_contains($html, '#'.$categoryWithTwoTickets->getId()), 'Category ids must be rendered.');
    ensureAdminCategories(str_contains($html, '000 Catégorie identique'), 'Category names must be rendered.');
    ensureAdminCategories(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category names must be escaped.');
    ensureAdminCategories(!str_contains($html, '<script>alert("category")</script>'), 'An unescaped category name was rendered.');
    ensureAdminCategories(str_contains($html, '>2<'), 'Category count 2 must be rendered.');
    ensureAdminCategories(str_contains($html, '>1<'), 'Category count 1 must be rendered.');
    ensureAdminCategories(str_contains($html, '>0<'), 'Category count 0 must be rendered.');
    ensureAdminCategories(!str_contains($html, '<form'), 'The admin categories page must not render any form.');
    ensureAdminCategories(!str_contains($html, 'method="post"'), 'The admin categories page must not render POST forms.');
    ensureAdminCategories(!str_contains($html, 'Créer'), 'The admin categories page must not expose create actions.');
    ensureAdminCategories(!str_contains($html, 'Modifier'), 'The admin categories page must not expose edit actions.');
    ensureAdminCategories(!str_contains($html, 'Supprimer'), 'The admin categories page must not expose delete actions.');
    ensureAdminCategories(!str_contains($html, 'Fusionner'), 'The admin categories page must not expose merge actions.');
    ensureAdminCategories(!str_contains($html, 'Archiver'), 'The admin categories page must not expose archive actions.');
    ensureAdminCategories($categoryCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM category'), 'Rendering admin categories must not persist categories.');
    ensureAdminCategories($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering admin categories must not mutate tickets.');

    $firstRenderedPosition = strpos($html, '<td>#'.$categoryWithTwoTickets->getId().'</td>');
    $secondRenderedPosition = strpos($html, '<td>#'.$categoryWithOneTicket->getId().'</td>');
    $thirdRenderedPosition = strpos($html, '<td>#'.$categoryWithoutTicket->getId().'</td>');
    ensureAdminCategories(
        false !== $firstRenderedPosition
        && false !== $secondRenderedPosition
        && false !== $thirdRenderedPosition
        && $firstRenderedPosition < $secondRenderedPosition
        && $secondRenderedPosition < $thirdRenderedPosition,
        'Rendered categories must follow repository ordering.',
    );

    $emptyHtml = $twig->render('admin/categories.html.twig', [
        'categories' => [],
    ]);
    ensureAdminCategories(str_contains($emptyHtml, 'Aucune catégorie disponible.'), 'The empty state is missing.');

    echo "Admin categories tests: PASS\n";
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
