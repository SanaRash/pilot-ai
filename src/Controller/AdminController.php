<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\AIAnalysisRepository;
use App\Repository\CategoryRepository;
use App\Repository\InterventionRepository;
use App\Repository\TicketHistoryRepository;
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

    #[Route('/admin/tickets/{id}', name: 'app_admin_ticket_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showTicket(
        Ticket $ticket,
        InterventionRepository $interventionRepository,
        TicketHistoryRepository $ticketHistoryRepository,
        AIAnalysisRepository $aiAnalysisRepository,
    ): Response {
        return $this->render('admin/ticket_show.html.twig', [
            'ticket' => $ticket,
            'interventions' => $interventionRepository->findBy(
                ['ticket' => $ticket],
                ['createdAt' => 'ASC', 'id' => 'ASC'],
            ),
            'ticket_history' => $ticketHistoryRepository->findBy(
                ['ticket' => $ticket],
                ['createdAt' => 'ASC', 'id' => 'ASC'],
            ),
            'ai_analyses' => $aiAnalysisRepository->findBy(
                ['ticket' => $ticket],
                ['createdAt' => 'DESC', 'id' => 'DESC'],
            ),
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

    #[Route('/admin/categories', name: 'app_admin_categories', methods: ['GET'])]
    public function categories(CategoryRepository $categoryRepository): Response
    {
        return $this->render('admin/categories.html.twig', [
            'categories' => $categoryRepository->findAllWithTicketCountForAdmin(),
        ]);
    }

    #[Route('/admin/statistics', name: 'app_admin_statistics', methods: ['GET'])]
    public function statistics(TicketRepository $ticketRepository): Response
    {
        $today = new \DateTimeImmutable('today');
        $start = $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $createdByDayCounts = $ticketRepository->countCreatedByDayForAdminStats($start, $end);
        $createdByDay = [];

        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $dateKey = $day->format('Y-m-d');
            $createdByDay[] = [
                'date' => $day,
                'ticketCount' => $createdByDayCounts[$dateKey] ?? 0,
            ];
        }

        return $this->render('admin/statistics.html.twig', [
            'total_ticket_count' => $ticketRepository->count([]),
            'status_counts' => [
                Ticket::STATUS_OPEN => $ticketRepository->countByStatus(Ticket::STATUS_OPEN),
                Ticket::STATUS_IN_PROGRESS => $ticketRepository->countByStatus(Ticket::STATUS_IN_PROGRESS),
                Ticket::STATUS_RESOLVED => $ticketRepository->countByStatus(Ticket::STATUS_RESOLVED),
                Ticket::STATUS_CLOSED => $ticketRepository->countByStatus(Ticket::STATUS_CLOSED),
            ],
            'priority_counts' => array_replace(
                array_fill_keys(Ticket::ALLOWED_PRIORITIES, 0),
                $ticketRepository->countByPriorityForAdminStats(),
            ),
            'category_counts' => $ticketRepository->countByCategoryForAdminStats(),
            'created_by_day' => $createdByDay,
            'status_labels' => self::STATUS_LABELS,
            'priority_labels' => self::PRIORITY_LABELS,
        ]);
    }
}
