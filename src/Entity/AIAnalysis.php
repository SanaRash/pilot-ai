<?php

namespace App\Entity;

use App\Repository\AIAnalysisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AIAnalysisRepository::class)]
class AIAnalysis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $suggestedPriority = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $suggestedCategory = null;

    #[ORM\Column(nullable: true)]
    private ?array $keywords = null;

    #[ORM\Column(nullable: true)]
    private ?array $suggestions = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'aIAnalyses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ticket $ticket = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getSuggestedPriority(): ?string
    {
        return $this->suggestedPriority;
    }

    public function setSuggestedPriority(?string $suggestedPriority): static
    {
        $this->suggestedPriority = $suggestedPriority;

        return $this;
    }

    public function getSuggestedCategory(): ?string
    {
        return $this->suggestedCategory;
    }

    public function setSuggestedCategory(?string $suggestedCategory): static
    {
        $this->suggestedCategory = $suggestedCategory;

        return $this;
    }

    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    public function setKeywords(?array $keywords): static
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function getSuggestions(): ?array
    {
        return $this->suggestions;
    }

    public function setSuggestions(?array $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }


    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): static
    {
        $this->ticket = $ticket;

        return $this;
    }
}
