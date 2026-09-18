<?php

namespace App\Controller;

use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\User;
use App\Form\InterventionType;
use App\Repository\CategoryRepository;
use App\Repository\InterventionRepository;
use App\Repository\TicketRepository;
use App\Service\TicketHistoryService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

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
    public function show(
        Ticket $ticket,
        CategoryRepository $categoryRepository,
        InterventionRepository $interventionRepository,
    ): Response
    {
        $interventionForm = $this->createForm(InterventionType::class, new Intervention(), [
            'action' => $this->generateUrl('app_technician_ticket_intervention_create', ['id' => $ticket->getId()]),
            'csrf_token_id' => 'add-intervention-'.$ticket->getId(),
            'method' => 'POST',
        ]);

        return $this->renderTicketDetails(
            $ticket,
            $categoryRepository,
            $interventionRepository,
            $interventionForm->createView(),
        );
    }

    #[Route('/technician/tickets/{id}/interventions', name: 'app_technician_ticket_intervention_create', methods: ['POST'])]
    public function createIntervention(
        Ticket $ticket,
        Request $request,
        CategoryRepository $categoryRepository,
        InterventionRepository $interventionRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isAssignedTechnician($ticket, $user)) {
            throw $this->createAccessDeniedException('Seul le technicien assigné peut ajouter une intervention.');
        }

        $intervention = new Intervention();
        $form = $this->createForm(InterventionType::class, $intervention, [
            'action' => $this->generateUrl('app_technician_ticket_intervention_create', ['id' => $ticket->getId()]),
            'csrf_token_id' => 'add-intervention-'.$ticket->getId(),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderTicketDetails(
                $ticket,
                $categoryRepository,
                $interventionRepository,
                $form->createView(),
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $intervention
            ->setTicket($ticket)
            ->setTechnician($user)
            ->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($intervention);
        $entityManager->flush();

        $this->addFlash('success', 'L’intervention a été ajoutée.');

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $ticket->getId(),
        ]);
    }

    private function renderTicketDetails(
        Ticket $ticket,
        CategoryRepository $categoryRepository,
        InterventionRepository $interventionRepository,
        FormView $interventionForm,
        ?Response $response = null,
    ): Response {
        return $this->render('technician/ticket_show.html.twig', [
            'ticket' => $ticket,
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
            'allowed_statuses' => Ticket::ALLOWED_STATUSES,
            'allowed_priorities' => Ticket::ALLOWED_PRIORITIES,
            'can_update_status' => $this->isAssignedTechnician($ticket, $this->getUser()),
            'can_update_priority' => $this->isAssignedTechnician($ticket, $this->getUser()),
            'can_update_category' => $this->isAssignedTechnician($ticket, $this->getUser()),
            'can_add_intervention' => $this->isAssignedTechnician($ticket, $this->getUser()),
            'intervention_form' => $interventionForm,
            'interventions' => $interventionRepository->findBy(
                ['ticket' => $ticket],
                ['createdAt' => 'ASC', 'id' => 'ASC'],
            ),
        ], $response);
    }

    #[Route('/technician/tickets/{id}/assign', name: 'app_technician_ticket_assign', methods: ['POST'])]
    public function assign(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TicketHistoryService $ticketHistoryService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userId = $user->getId();

        if (null === $userId) {
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
                $ticketHistoryService->record(
                    $ticket,
                    'TICKET_ASSIGNED',
                    null,
                    (string) $userId,
                    $user,
                );
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
    public function updateStatus(
        Ticket $ticket,
        Request $request,
        TicketHistoryService $ticketHistoryService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticketId = $ticket->getId();

        if (null === $ticketId || !$this->isAssignedTechnician($ticket, $user)) {
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

        $oldStatus = $ticket->getStatus();

        if ($oldStatus === $status) {
            $this->addFlash('info', 'Le ticket possède déjà ce statut.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $ticket->setStatus($status);
        $ticketHistoryService->record(
            $ticket,
            'STATUS_CHANGED',
            $oldStatus,
            $status,
            $user,
        );

        $this->addFlash('success', 'Le statut du ticket a été mis à jour.');

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $ticketId,
        ]);
    }

    #[Route('/technician/tickets/{id}/priority', name: 'app_technician_ticket_priority', methods: ['POST'])]
    public function updatePriority(
        Ticket $ticket,
        Request $request,
        TicketHistoryService $ticketHistoryService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticketId = $ticket->getId();

        if (null === $ticketId || !$this->isAssignedTechnician($ticket, $user)) {
            throw $this->createAccessDeniedException('Seul le technicien assigné peut modifier la priorité.');
        }

        if (!$this->isCsrfTokenValid('update-ticket-priority-'.$ticketId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $priority = $request->request->getString('priority');

        if (!in_array($priority, Ticket::ALLOWED_PRIORITIES, true)) {
            $this->addFlash('error', 'La priorité sélectionnée est invalide.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $oldPriority = $ticket->getPriority();

        if ($oldPriority === $priority) {
            $this->addFlash('info', 'Le ticket possède déjà cette priorité.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $ticket->setPriority($priority);
        $ticketHistoryService->record(
            $ticket,
            'PRIORITY_CHANGED',
            $oldPriority,
            $priority,
            $user,
        );

        $this->addFlash('success', 'La priorité du ticket a été mise à jour.');

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $ticketId,
        ]);
    }

    #[Route('/technician/tickets/{id}/category', name: 'app_technician_ticket_category', methods: ['POST'])]
    public function updateCategory(
        Ticket $ticket,
        Request $request,
        CategoryRepository $categoryRepository,
        TicketHistoryService $ticketHistoryService,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ticketId = $ticket->getId();

        if (null === $ticketId || !$this->isAssignedTechnician($ticket, $user)) {
            throw $this->createAccessDeniedException('Seul le technicien assigné peut modifier la catégorie.');
        }

        if (!$this->isCsrfTokenValid('update-ticket-category-'.$ticketId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $submittedCategoryId = $request->request->all()['category_id'] ?? null;
        $categoryId = is_string($submittedCategoryId) && 1 === preg_match('/^[1-9][0-9]*$/D', $submittedCategoryId)
            ? filter_var($submittedCategoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (false === $categoryId) {
            $this->addFlash('error', 'La catégorie sélectionnée est invalide.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $category = $categoryRepository->find($categoryId);

        if (null === $category) {
            $this->addFlash('error', 'La catégorie sélectionnée est introuvable.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $oldCategoryId = $ticket->getCategory()?->getId();

        if ($oldCategoryId === $categoryId) {
            $this->addFlash('info', 'Le ticket possède déjà cette catégorie.');

            return $this->redirectToRoute('app_technician_ticket_show', [
                'id' => $ticketId,
            ]);
        }

        $ticket->setCategory($category);
        $ticketHistoryService->record(
            $ticket,
            'CATEGORY_CHANGED',
            null === $oldCategoryId ? null : (string) $oldCategoryId,
            (string) $categoryId,
            $user,
        );

        $this->addFlash('success', 'La catégorie du ticket a été mise à jour.');

        return $this->redirectToRoute('app_technician_ticket_show', [
            'id' => $ticketId,
        ]);
    }

    private function isAssignedTechnician(Ticket $ticket, ?UserInterface $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $userId = $user->getId();
        $assignedToId = $ticket->getAssignedTo()?->getId();

        return null !== $userId && $assignedToId === $userId;
    }
}
