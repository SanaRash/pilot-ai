<?php

function ensureResponsiveStyles(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function responsiveTemplate(string $path): string
{
    $content = file_get_contents(__DIR__ . '/../../' . $path);

    if (false === $content) {
        throw new RuntimeException(sprintf('Unable to read %s.', $path));
    }

    return $content;
}

$base = responsiveTemplate('templates/base.html.twig');
$roleNavigation = responsiveTemplate('templates/_role_navigation.html.twig');
$clientDashboard = responsiveTemplate('templates/client/index.html.twig');
$technicianDetail = responsiveTemplate('templates/technician/ticket_show.html.twig');
$adminUsers = responsiveTemplate('templates/admin/users.html.twig');
$adminCategories = responsiveTemplate('templates/admin/categories.html.twig');

ensureResponsiveStyles(str_contains($base, 'box-sizing: border-box;'), 'Global box-sizing rule is missing.');
ensureResponsiveStyles(str_contains($base, 'max-width: 100%;'), 'Global max-width protection is missing.');
ensureResponsiveStyles(str_contains($base, 'overflow-wrap: anywhere;'), 'Long-content overflow protection is missing.');
ensureResponsiveStyles(str_contains($base, '@media (max-width: 48rem)'), 'Base mobile breakpoint is missing.');
ensureResponsiveStyles(str_contains($base, '.role-navigation__link {') && str_contains($base, 'min-height: 2.5rem;'), 'Navigation links must keep comfortable touch targets.');
ensureResponsiveStyles(str_contains($base, '.role-navigation__link {
                    width: 100%;'), 'Navigation links must stack on narrow screens.');

ensureResponsiveStyles(!str_contains($roleNavigation, '<button'), 'Role navigation must not add a JavaScript/burger button.');
ensureResponsiveStyles(!str_contains(strtolower($roleNavigation), 'burger'), 'Role navigation must not introduce a burger menu.');
ensureResponsiveStyles(!str_contains(strtolower($roleNavigation), 'dropdown'), 'Role navigation must not introduce a dropdown menu.');

ensureResponsiveStyles(str_contains($clientDashboard, '@media (max-width: 48rem)'), 'Client dashboard mobile breakpoint is missing.');
ensureResponsiveStyles(str_contains($clientDashboard, '.dashboard-ticket {
                align-items: stretch;
                flex-direction: column;'), 'Client dashboard cards must stack on mobile.');
ensureResponsiveStyles(str_contains($clientDashboard, '.client-dashboard__action {
                width: 100%;'), 'Client dashboard actions must be full-width on mobile.');

ensureResponsiveStyles(str_contains($technicianDetail, '@media (max-width: 60rem)'), 'Technician detail breakpoint must be preserved.');
ensureResponsiveStyles(str_contains($technicianDetail, '.technician-ticket-detail__card {
            min-width: 0;'), 'Technician detail cards must be protected against overflow.');
ensureResponsiveStyles(str_contains($technicianDetail, '.technician-ticket-detail__item {
            min-width: 0;
            overflow-wrap: anywhere;'), 'Technician detail list items must wrap long content.');
ensureResponsiveStyles(str_contains($technicianDetail, '.technician-ticket-detail__button {
                width: 100%;'), 'Technician detail buttons must be full-width on narrow screens.');

foreach ([$adminUsers, $adminCategories] as $adminTemplate) {
    ensureResponsiveStyles(str_contains($adminTemplate, 'overflow-x: auto;'), 'Admin table horizontal scroll wrapper is missing.');
    ensureResponsiveStyles(str_contains($adminTemplate, '-webkit-overflow-scrolling: touch;'), 'Admin table touch scrolling is missing.');
}

ensureResponsiveStyles(!str_contains($base, 'setStatus('), 'Responsive task must not add ticket status mutation.');
ensureResponsiveStyles(!str_contains($base, 'setPriority('), 'Responsive task must not add ticket priority mutation.');
ensureResponsiveStyles(!str_contains($base, 'setCategory('), 'Responsive task must not add ticket category mutation.');
ensureResponsiveStyles(!str_contains($base, 'setAssignedTo('), 'Responsive task must not add ticket assignment mutation.');

echo "Responsive styles tests: PASS
";
