<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Form\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientTicketController extends AbstractController
{
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