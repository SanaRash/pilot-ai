<?php

declare(strict_types=1);

use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\AIAnalysisRepository;
use App\Repository\TicketRepository;
use App\Service\SimilarTicketFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureSimilarity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ticket(string $title, string $description, DateTimeImmutable $createdAt): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt($createdAt);
}

function analysis(Ticket $ticket, array $keywords, DateTimeImmutable $createdAt): AIAnalysis
{
    return (new AIAnalysis())
        ->setTicket($ticket)
        ->setSummary('Résumé')
        ->setSuggestedPriority(Ticket::PRIORITY_MEDIUM)
        ->setSuggestedCategory('Support')
        ->setKeywords($keywords)
        ->setSuggestions(['Vérifier le service'])
        ->setCreatedAt($createdAt);
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
ensureSimilarity($entityManager instanceof EntityManagerInterface, 'Doctrine did not provide an ORM entity manager.');

$ticketRepository = $entityManager->getRepository(Ticket::class);
$analysisRepository = $entityManager->getRepository(AIAnalysis::class);
ensureSimilarity($ticketRepository instanceof TicketRepository, 'Unexpected ticket repository.');
ensureSimilarity($analysisRepository instanceof AIAnalysisRepository, 'Unexpected AI analysis repository.');
$finder = new SimilarTicketFinder($ticketRepository, $analysisRepository);

$scoreTicket = ticket('Incident connexion', 'Description courante', new DateTimeImmutable('2026-09-21 09:00:00'));
$scoreCandidate = ticket('  INCIDENT CONNEXION  ', 'Erreur réseau détectée', new DateTimeImmutable('2026-09-21 10:00:00'));
ensureSimilarity(
    8 === $finder->calculateScore($scoreTicket, $scoreCandidate, []),
    'An exact normalized title must add exactly 8.',
);
ensureSimilarity(
    2 === $finder->calculateScore($scoreTicket, ticket('Connexion lente', 'RAS', new DateTimeImmutable()), ['connexion']),
    'A title term must add exactly 2.',
);
ensureSimilarity(
    1 === $finder->calculateScore($scoreTicket, ticket('Incident distinct', 'Connexion lente', new DateTimeImmutable()), ['connexion']),
    'A description term must add exactly 1.',
);
ensureSimilarity(
    0 === $finder->calculateScore($scoreTicket, ticket('Autre sujet', 'Sans rapport', new DateTimeImmutable()), ['mot']),
    'Terms shorter than four characters must not affect the score.',
);

$connection = $entityManager->getConnection();
$connection->beginTransaction();

try {
    $technician = (new User())
        ->setEmail('similarity-technician-'.bin2hex(random_bytes(6)).'@example.test')
        ->setRoles(['ROLE_TECHNICIAN'])
        ->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT))
        ->setFirstname('Tech')
        ->setLastname('Similarity')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable());
    $client = (new User())
        ->setEmail('similarity-client-'.bin2hex(random_bytes(6)).'@example.test')
        ->setRoles(['ROLE_CLIENT'])
        ->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT))
        ->setFirstname('Client')
        ->setLastname('Similarity')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable());
    $category = (new Category())->setName('Réseau '.bin2hex(random_bytes(4)));
    $otherCategory = (new Category())->setName('Matériel '.bin2hex(random_bytes(4)));

    $current = ticket('Connexion VPN impossible', 'Erreur réseau au bureau', new DateTimeImmutable('2026-09-20 08:00:00'))
        ->setCreatedBy($client)
        ->setCategory($category);
    $exactTitle = ticket('connexion vpn impossible', 'Incident distinct', new DateTimeImmutable('2026-09-20 09:00:00'))
        ->setCreatedBy($client)
        ->setCategory($otherCategory);
    $keywordCandidate = ticket('Certificat expiré', 'Le tunnel ultrasecret échoue', new DateTimeImmutable('2026-09-20 10:00:00'))
        ->setCreatedBy($client)
        ->setCategory($otherCategory);
    $oldKeywordOnly = ticket('Ancien incident', 'Le terme ancienmot est présent', new DateTimeImmutable('2026-09-20 11:00:00'))
        ->setCreatedBy($client)
        ->setCategory($otherCategory);
    $categoryOnly = ticket('Imprimante bloquée', 'Papier coincé', new DateTimeImmutable('2026-09-20 12:00:00'))
        ->setCreatedBy($client)
        ->setCategory($category);
    $unrelated = ticket('Écran cassé', 'Dalle endommagée', new DateTimeImmutable('2026-09-20 13:00:00'))
        ->setCreatedBy($client)
        ->setCategory($otherCategory);

    foreach ([$technician, $client, $category, $otherCategory, $current, $exactTitle, $keywordCandidate, $oldKeywordOnly, $categoryOnly, $unrelated] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $sameAnalysisDate = new DateTimeImmutable('2026-09-20 14:00:00');
    $oldAnalysis = analysis($current, ['ancienmot'], $sameAnalysisDate);
    $latestAnalysis = analysis($current, ['ultrasecret'], $sameAnalysisDate);
    $entityManager->persist($oldAnalysis);
    $entityManager->persist($latestAnalysis);
    $entityManager->flush();

    ensureSimilarity($latestAnalysis === $analysisRepository->findLatestForTicket($current), 'Latest analysis must use createdAt DESC then id DESC.');
    ensureSimilarity(
        3 === $finder->calculateScore($current, $categoryOnly, []),
        'A shared non-null category must add exactly 3.',
    );

    $currentBefore = serialize($current);
    $similar = $finder->findSimilar($current, $technician);
    ensureSimilarity(!in_array($current, $similar, true), 'The current ticket must always be excluded.');
    ensureSimilarity(in_array($exactTitle, $similar, true), 'Exact title candidate is missing.');
    ensureSimilarity(in_array($keywordCandidate, $similar, true), 'Latest AI keyword candidate is missing.');
    ensureSimilarity(!in_array($oldKeywordOnly, $similar, true), 'An older AI keyword must not be used.');
    ensureSimilarity(in_array($categoryOnly, $similar, true), 'Same-category candidate is missing.');
    ensureSimilarity(!in_array($unrelated, $similar, true), 'A zero-score candidate must be excluded.');
    ensureSimilarity($exactTitle === $similar[0], 'The highest score must be returned first.');
    ensureSimilarity($currentBefore === serialize($current), 'Similarity search mutated the current ticket.');

    $shortCurrent = ticket('VPN', 'DNS', new DateTimeImmutable('2026-09-20 15:00:00'))->setCreatedBy($client);
    $shortExactTitle = ticket(' vpn ', 'SSH', new DateTimeImmutable('2026-09-20 16:00:00'))->setCreatedBy($client);
    $entityManager->persist($shortCurrent);
    $entityManager->persist($shortExactTitle);
    $entityManager->flush();
    ensureSimilarity(
        [$shortExactTitle] === $finder->findSimilar($shortCurrent, $technician),
        'An exact normalized short title must reach scoring even without category or four-character terms.',
    );
    $spacingCurrent = ticket('VPN DNS', 'SSH', new DateTimeImmutable('2026-09-20 16:30:00'))->setCreatedBy($client);
    $differentInternalSpacing = ticket('vpn   dns', 'FTP', new DateTimeImmutable('2026-09-20 17:00:00'))->setCreatedBy($client);
    $entityManager->persist($spacingCurrent);
    $entityManager->persist($differentInternalSpacing);
    $entityManager->flush();
    ensureSimilarity(
        0 === $finder->calculateScore($spacingCurrent, $differentInternalSpacing, []),
        'Internal spacing must remain significant in both prefiltering and scoring.',
    );
    ensureSimilarity(
        [] === $finder->findSimilar($spacingCurrent, $technician),
        'Prefiltering and scoring must agree when internal title spacing differs.',
    );

    try {
        $finder->findSimilar($current, $client);
        throw new RuntimeException('A client must not be allowed to search similar tickets.');
    } catch (AccessDeniedException) {
    }

    $limitCategory = (new Category())->setName('Limite '.bin2hex(random_bytes(4)));
    $limitCurrent = ticket('Sujet sans correspondance lexicale', 'Description unique', new DateTimeImmutable('2026-09-21 08:00:00'))
        ->setCreatedBy($client)
        ->setCategory($limitCategory);
    $entityManager->persist($limitCategory);
    $entityManager->persist($limitCurrent);

    $expectedNewest = [];
    $candidateBaseDate = new DateTimeImmutable('2026-09-21 09:00:00');
    for ($index = 1; $index <= 51; ++$index) {
        $candidateDate = $candidateBaseDate->modify(sprintf('+%d minutes', $index >= 50 ? 51 : $index));
        $candidate = ticket(
            'Candidat '.$index,
            'Autre contenu '.$index,
            $candidateDate,
        )
            ->setCreatedBy($client)
            ->setCategory($limitCategory);
        $entityManager->persist($candidate);
        $expectedNewest[] = $candidate;
    }
    $entityManager->flush();

    $limitedResults = $finder->findSimilar($limitCurrent, $technician);
    ensureSimilarity(5 === count($limitedResults), 'At most five similar tickets must be returned.');
    ensureSimilarity(
        array_reverse(array_slice($expectedNewest, -5)) === $limitedResults,
        'Equal scores must be ordered by createdAt DESC then id DESC.',
    );
    ensureSimilarity(
        50 === count($ticketRepository->findSimilarityCandidates($limitCurrent, [], 'sujet sans correspondance lexicale', 100)),
        'Candidate prefilter must enforce its hard limit of 50.',
    );
} finally {
    $entityManager->clear();
    $connection->rollBack();
}

echo "SimilarTicketFinder tests: PASS\n";
