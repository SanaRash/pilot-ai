<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TechnicianController extends AbstractController
{
    #[Route('/technician/tickets', name: 'app_technician_tickets')]
    public function tickets(TicketRepository $ticketRepository): Response
    {
        $tickets = $ticketRepository->findBy(
            [],
            ['createdAt' => 'DESC']
        );

        return $this->render('technician/tickets.html.twig', [
            'tickets' => $tickets,
        ]);
    }

    #[Route('/technician/tickets/{id}', name: 'app_technician_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        return $this->render('technician/ticket_show.html.twig', [
            'ticket' => $ticket,
        ]);
    }
}
