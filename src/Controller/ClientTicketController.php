<?php

namespace App\Controller;

use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\Entity\Ticket;
use App\Entity\User;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientTicketController extends AbstractController
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly LoggerInterface $logger,
    ) {
    }

    private const array STATUS_LABELS = [
        Ticket::STATUS_OPEN => 'Ouvert',
        Ticket::STATUS_IN_PROGRESS => 'En cours',
        Ticket::STATUS_RESOLVED => 'Résolu',
        Ticket::STATUS_CLOSED => 'Fermé',
    ];

    #[Route('/client/tickets', name: 'app_client_tickets', methods: ['GET'])]
    public function tickets(TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('client_ticket/tickets.html.twig', [
            'tickets' => $ticketRepository->findAllForClient($user),
            'status_labels' => self::STATUS_LABELS,
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
            'status_labels' => self::STATUS_LABELS,
        ]);
    }

    #[Route('/client/ticket/new', name: 'app_client_ticket_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        TicketHistoryService $ticketHistoryService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticket = new Ticket();

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ticket->setStatus('OPEN');
            $ticket->setPriority('MEDIUM');
            $ticket->setSource('APP');
            $ticket->setCreatedAt(new \DateTimeImmutable());
            $ticket->setUpdatedAt(null);
            $ticket->setCreatedBy($user);

            $entityManager->persist($ticket);
            $ticketHistoryService->record(
                $ticket,
                'TICKET_CREATED',
                null,
                null,
                $user,
            );

            try {
                $this->aiService->analyzeTicket($ticket);
            } catch (AIProviderException|AIValidationException $exception) {
                $this->logger->warning('L’analyse IA du ticket client est indisponible.', [
                    'event' => 'client_ticket_ai_unavailable',
                    'step' => 'ai_analysis',
                    'exception' => $exception::class,
                    'providerStatus' => $exception->getCode() ?: null,
                    'ticketId' => $ticket->getId(),
                ]);
            } catch (ORMException|DBALException $exception) {
                $this->logger->warning('La persistance de l’analyse IA du ticket client a échoué.', [
                    'event' => 'client_ticket_ai_persistence_failed',
                    'step' => 'ai_analysis_persistence',
                    'exception' => $exception::class,
                    'ticketId' => $ticket->getId(),
                ]);
            }

            $this->addFlash(
                'success',
                'Votre demande a bien été envoyée.'
            );

            return $this->redirectToRoute('app_client_tickets');
        }

        return $this->render('client_ticket/new.html.twig', [
            'form' => $form,
        ]);
    }
}
