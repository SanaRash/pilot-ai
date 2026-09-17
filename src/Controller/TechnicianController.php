<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
            'allowed_statuses' => Ticket::ALLOWED_STATUSES,
        ]);
    }

    #[Route('/technician/tickets/{id}/assign', name: 'app_technician_ticket_assign', methods: ['POST'])]
    public function assign(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('assign-ticket-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $entityManager->beginTransaction();

        try {
            $ticket = $entityManager->find(Ticket::class, $id, LockMode::PESSIMISTIC_WRITE);

            if (!$ticket instanceof Ticket) {
                throw $this->createNotFoundException('Ticket introuvable.');
            }

            if (null === $ticket->getAssignedTo()) {
                $ticket->setAssignedTo($user);
                $entityManager->flush();
                $flashType = 'success';
                $flashMessage = 'Le ticket vous a été assigné.';
            } elseif ($ticket->getAssignedTo() === $user) {
                $flashType = 'info';
                $flashMessage = 'Ce ticket vous est déjà assigné.';
            } else {
                $flashType = 'warning';
                $flashMessage = 'Ce ticket est déjà assigné à un autre technicien.';
            }

            $entityManager->commit();
        } catch (\Throwable $exception) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }

            throw $exception;
        }

        $this->addFlash($flashType, $flashMessage);

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $id,
        ]);
    }

    #[Route('/technician/tickets/{id}/status', name: 'app_technician_ticket_status', methods: ['POST'])]
    public function updateStatus(Ticket $ticket, Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticketId = $ticket->getId();
        $userId = $user->getId();
        $assignedToId = $ticket->getAssignedTo()?->getId();

        if (null === $ticketId || null === $userId || $assignedToId !== $userId) {
            throw $this->createAccessDeniedException('Seul le technicien assigné peut modifier le statut.');
        }

        if (!$this->isCsrfTokenValid('update-ticket-status-'.$ticketId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $status = $request->request->getString('status');

        if (!in_array($status, Ticket::ALLOWED_STATUSES, true)) {
            $this->addFlash('error', 'Le statut sélectionné est invalide.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $ticket->setStatus($status);
        $entityManager->flush();

        $this->addFlash('success', 'Le statut du ticket a été mis à jour.');

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $ticketId,
        ]);
    }
}
