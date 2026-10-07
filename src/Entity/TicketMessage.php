<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketMessageRepository::class)]
#[ORM\Table(name: 'ticket_message')]
#[ORM\Index(name: 'IDX_TICKET_MESSAGE_TICKET', columns: ['ticket_id'])]
#[ORM\Index(name: 'IDX_TICKET_MESSAGE_AUTHOR', columns: ['author_user_id'])]
#[ORM\CheckConstraint(
    name: 'CHK_TICKET_MESSAGE_AUTHOR',
    options: ['check' => "(author_type = 'CLIENT' AND author_user_id IS NOT NULL) OR (author_type = 'BOT' AND author_user_id IS NULL)"],
)]
class TicketMessage
{
    public const AUTHOR_CLIENT = 'CLIENT';
    public const AUTHOR_BOT = 'BOT';
    public const MAX_CONTENT_LENGTH = 2_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\Column(length: 20)]
    private string $authorType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $authorUser;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ticket $ticket, string $authorType, ?User $authorUser, string $content)
    {
        if (
            !in_array($authorType, [self::AUTHOR_CLIENT, self::AUTHOR_BOT], true)
            || (self::AUTHOR_CLIENT === $authorType && null === $authorUser)
            || (self::AUTHOR_BOT === $authorType && null !== $authorUser)
        ) {
            throw new \InvalidArgumentException('The ticket message author is invalid.');
        }

        $content = trim($content);
        if ('' === $content || mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_LENGTH) {
            throw new \InvalidArgumentException('The ticket message content is invalid.');
        }

        $this->ticket = $ticket;
        $this->authorType = $authorType;
        $this->authorUser = $authorUser;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function getAuthorUser(): ?User
    {
        return $this->authorUser;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
