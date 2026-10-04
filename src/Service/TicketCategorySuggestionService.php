<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Ticket;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class TicketCategorySuggestionService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $entityManager,
        private TicketHistoryService $ticketHistoryService,
    ) {
    }

    public function applySuggestion(Ticket $ticket, ?string $suggestedCategory): bool
    {
        if (null !== $ticket->getCategory() || null === $suggestedCategory) {
            return false;
        }

        $normalizedSuggestion = self::normalize($suggestedCategory);

        if ('' === $normalizedSuggestion) {
            return false;
        }

        try {
            $matches = array_values(array_filter(
                $this->categoryRepository->findAll(),
                static fn (Category $category): bool => self::normalize((string) $category->getName()) === $normalizedSuggestion,
            ));

            if (1 !== count($matches)) {
                return false;
            }

            $ticketId = $ticket->getId();

            if (null === $ticketId) {
                return false;
            }

            /** @var Connection $connection */
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();

            try {
                $this->entityManager->refresh($ticket, LockMode::PESSIMISTIC_WRITE);

                if (null !== $ticket->getCategory()) {
                    $connection->commit();

                    return false;
                }

                $category = $matches[0];
                $ticket->setCategory($category);
                $this->ticketHistoryService->record(
                    $ticket,
                    'CATEGORY_AUTO_ASSIGNED',
                    null,
                    (string) $category->getId(),
                    null,
                );
                $connection->commit();

                return true;
            } catch (Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                if ($this->entityManager->isOpen()) {
                    $ticket->setCategory(null);
                }

                throw $exception;
            }
        } catch (Throwable $exception) {
            throw new TicketCategorySuggestionException(
                'Automatic ticket category assignment failed.',
                previous: $exception,
            );
        }
    }

    private static function normalize(string $value): string
    {
        $collapsedWhitespace = preg_replace('/\s+/u', ' ', $value);

        if (null === $collapsedWhitespace) {
            return '';
        }

        return mb_strtolower(trim($collapsedWhitespace), 'UTF-8');
    }
}
