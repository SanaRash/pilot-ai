<?php

namespace App\Controller;

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
}