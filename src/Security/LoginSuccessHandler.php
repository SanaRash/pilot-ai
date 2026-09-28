<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        $roles = $user instanceof UserInterface ? $user->getRoles() : $token->getRoleNames();

        $route = match (true) {
            in_array('ROLE_ADMIN', $roles, true) => 'app_admin',
            in_array('ROLE_TECHNICIAN', $roles, true) => 'app_technician',
            in_array('ROLE_CLIENT', $roles, true) => 'app_client',
            default => 'app_login',
        };

        return new RedirectResponse($this->router->generate($route));
    }
}
