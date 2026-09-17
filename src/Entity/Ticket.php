<?php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(length: 20)]
    private ?string $priority = null;

    #[ORM\Column(length: 20)]
    private ?string $source = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'tickets')]
    private ?Category $category = null;

    #[ORM\ManyToOne(inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

   #[ORM\ManyToOne(inversedBy: 'assignedTickets')]
   private ?User $assignedTo = null;

    /**
     * @var Collection<int, Intervention>
     */
    #[ORM\OneToMany(targetEntity: Intervention::class, mappedBy: 'ticket')]
    private Collection $interventions;

    /**
     * @var Collection<int, AIAnalysis>
     */
    #[ORM\OneToMany(targetEntity: AIAnalysis::class, mappedBy: 'ticket')]
    private Collection $aIAnalyses;

    /**
     * @var Collection<int, TicketHistory>
     */
    #[ORM\OneToMany(targetEntity: TicketHistory::class, mappedBy: 'ticket')]
    private Collection $ticketHistories;

    public function __construct()
    {
        $this->interventions = new ArrayCollection();
        $this->aIAnalyses = new ArrayCollection();
        $this->ticketHistories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    /**
     * @return Collection<int, Intervention>
     */
    public function getInterventions(): Collection
    {
        return $this->interventions;
    }

    public function addIntervention(Intervention $intervention): static
    {
        if (!$this->interventions->contains($intervention)) {
            $this->interventions->add($intervention);
            $intervention->setTicket($this);
        }

        return $this;
    }

    public function removeIntervention(Intervention $intervention): static
    {
        if ($this->interventions->removeElement($intervention)) {
            // set the owning side to null (unless already changed)
            if ($intervention->getTicket() === $this) {
                $intervention->setTicket(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AIAnalysis>
     */
    public function getAIAnalyses(): Collection
    {
        return $this->aIAnalyses;
    }

    public function addAIAnalysis(AIAnalysis $aIAnalysis): static
    {
        if (!$this->aIAnalyses->contains($aIAnalysis)) {
            $this->aIAnalyses->add($aIAnalysis);
            $aIAnalysis->setTicket($this);
        }

        return $this;
    }

    public function removeAIAnalysis(AIAnalysis $aIAnalysis): static
    {
        if ($this->aIAnalyses->removeElement($aIAnalysis)) {
            // set the owning side to null (unless already changed)
            if ($aIAnalysis->getTicket() === $this) {
                $aIAnalysis->setTicket(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TicketHistory>
     */
    public function getTicketHistories(): Collection
    {
        return $this->ticketHistories;
    }

    public function addTicketHistory(TicketHistory $ticketHistory): static
    {
        if (!$this->ticketHistories->contains($ticketHistory)) {
            $this->ticketHistories->add($ticketHistory);
            $ticketHistory->setTicket($this);
        }

        return $this;
    }

    public function removeTicketHistory(TicketHistory $ticketHistory): static
    {
        if ($this->ticketHistories->removeElement($ticketHistory)) {
            // set the owning side to null (unless already changed)
            if ($ticketHistory->getTicket() === $this) {
                $ticketHistory->setTicket(null);
            }
        }

        return $this;
    }
}
