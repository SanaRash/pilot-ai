<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class TicketHistoryService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(
        Ticket $ticket,
        string $action,
        ?string $oldValue,
        ?string $newValue,
        ?User $changedBy,
    ): TicketHistory {
        $history = (new TicketHistory())
            ->setTicket($ticket)
            ->setAction($action)
            ->setOldValue($oldValue)
            ->setNewValue($newValue)
            ->setChangedBy($changedBy)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        return $history;
    }
}
