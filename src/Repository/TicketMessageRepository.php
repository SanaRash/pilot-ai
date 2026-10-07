<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\TicketMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketMessage>
 */
class TicketMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketMessage::class);
    }

    /**
     * @return list<TicketMessage>
     */
    public function findConversationForTicket(Ticket $ticket): array
    {
        return $this->findBy(
            ['ticket' => $ticket],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }
}
