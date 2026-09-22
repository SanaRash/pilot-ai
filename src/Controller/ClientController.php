<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientController extends AbstractController
{
    private const array STATUS_LABELS = [
        Ticket::STATUS_OPEN => 'Ouvert',
        Ticket::STATUS_IN_PROGRESS => 'En cours',
        Ticket::STATUS_RESOLVED => 'Résolu',
        Ticket::STATUS_CLOSED => 'Fermé',
    ];

    #[Route('/client', name: 'app_client', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('client/index.html.twig', [
            'client' => $user,
            'recent_tickets' => $ticketRepository->findRecentForClient($user),
            'status_labels' => self::STATUS_LABELS,
        ]);
    }
}
