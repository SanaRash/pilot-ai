<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientTicketController extends AbstractController
{
    #[Route('/client/tickets', name: 'app_client_tickets', methods: ['GET'])]
    public function tickets(TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('client_ticket/tickets.html.twig', [
            'tickets' => $ticketRepository->findBy(
                ['createdBy' => $user],
                ['createdAt' => 'DESC'],
            ),
        ]);
    }

    #[Route('/client/tickets/{id}', name: 'app_client_ticket_show', methods: ['GET'])]
    public function show(int $id, TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticket = $ticketRepository->findOneBy([
            'id' => $id,
            'createdBy' => $user,
        ]);

        if (!$ticket instanceof Ticket) {
            throw $this->createNotFoundException();
        }

        return $this->render('client_ticket/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/client/ticket/new', name: 'app_client_ticket_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $ticket = new Ticket();

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ticket->setStatus('OPEN');
            $ticket->setPriority('MEDIUM');
            $ticket->setSource('APP');
            $ticket->setCreatedAt(new \DateTimeImmutable());
            $ticket->setUpdatedAt(null);

            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            $ticket->setCreatedBy($user);

            $entityManager->persist($ticket);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Votre ticket a bien été créé.'
            );

            return $this->redirectToRoute('app_client_ticket_new');
        }

        return $this->render('client_ticket/new.html.twig', [
            'form' => $form,
        ]);
    }
}
