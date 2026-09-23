<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\CategoryRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    private const array STATUS_LABELS = [
        Ticket::STATUS_OPEN => 'Ouvert',
        Ticket::STATUS_IN_PROGRESS => 'En cours',
        Ticket::STATUS_RESOLVED => 'Résolu',
        Ticket::STATUS_CLOSED => 'Fermé',
    ];

    private const array PRIORITY_LABELS = [
        Ticket::PRIORITY_LOW => 'Basse',
        Ticket::PRIORITY_MEDIUM => 'Moyenne',
        Ticket::PRIORITY_HIGH => 'Haute',
        Ticket::PRIORITY_URGENT => 'Urgente',
    ];

    private const array ROLE_LABELS = [
        'ROLE_CLIENT' => 'Client',
        'ROLE_TECHNICIAN' => 'Technicien',
        'ROLE_ADMIN' => 'Administrateur',
    ];

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(
        TicketRepository $ticketRepository,
        UserRepository $userRepository,
        CategoryRepository $categoryRepository,
    ): Response
    {
        return $this->render('admin/index.html.twig', [
            'open_ticket_count' => $ticketRepository->countByStatus(Ticket::STATUS_OPEN),
            'open_unassigned_ticket_count' => $ticketRepository->countOpenUnassignedTickets(),
            'in_progress_ticket_count' => $ticketRepository->countByStatus(Ticket::STATUS_IN_PROGRESS),
            'resolved_ticket_count' => $ticketRepository->countByStatus(Ticket::STATUS_RESOLVED),
            'closed_ticket_count' => $ticketRepository->countByStatus(Ticket::STATUS_CLOSED),
            'client_count' => $userRepository->countByPersistedRole('ROLE_CLIENT'),
            'technician_count' => $userRepository->countByPersistedRole('ROLE_TECHNICIAN'),
            'category_count' => $categoryRepository->count([]),
            'recent_tickets' => $ticketRepository->findRecentForAdmin(),
            'status_labels' => self::STATUS_LABELS,
            'priority_labels' => self::PRIORITY_LABELS,
        ]);
    }

    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET'])]
    public function users(UserRepository $userRepository): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $userRepository->findAllForAdminList(),
            'role_labels' => self::ROLE_LABELS,
        ]);
    }
}
