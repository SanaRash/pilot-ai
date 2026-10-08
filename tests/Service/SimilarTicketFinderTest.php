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
    10 === $finder->calculateScore($scoreTicket, $scoreCandidate, []),
    'An exact normalized title must add exactly 10.',
);
ensureSimilarity(
    3 === $finder->calculateScore($scoreTicket, ticket('Connexion lente', 'RAS', new DateTimeImmutable()), ['connexion']),
    'A title term must add exactly 3.',
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
    $networkCategory = (new Category())->setName('Réseau '.bin2hex(random_bytes(4)));
    $hardwareCategory = (new Category())->setName('Matériel '.bin2hex(random_bytes(4)));
    $printCategory = (new Category())->setName('Impression '.bin2hex(random_bytes(4)));
    $marketingCategory = (new Category())->setName('Marketing '.bin2hex(random_bytes(4)));

    $current = ticket(
        'Écran noir matériel',
        'Bonjour, mon ordinateur démarre mais l’écran reste noir. J’ai déjà vérifié le câble et redémarré. Merci.',
        new DateTimeImmutable('2026-09-20 08:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($hardwareCategory);
    $relevantScreen = ticket(
        'Écran noir au démarrage',
        'Affichage noir au lancement avec contrôle du câble vidéo et test sur écran externe.',
        new DateTimeImmutable('2026-09-20 09:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($hardwareCategory);
    $printerFalsePositive = ticket(
        'Imprimante hors ligne',
        'Bonjour, j’ai déjà vérifié le câble et redémarré l’imprimante. Merci.',
        new DateTimeImmutable('2026-09-20 10:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($printCategory);
    $wifiFalsePositive = ticket(
        'Impossible d’accéder au Wi-Fi',
        'Mon ordinateur est connecté mais Internet ne fonctionne pas.',
        new DateTimeImmutable('2026-09-20 11:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($networkCategory);
    $marketingFalsePositive = ticket(
        'Growth Marketer IA',
        'Mais le résultat reste déjà différent de la demande, merci.',
        new DateTimeImmutable('2026-09-20 12:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($marketingCategory);
    $sameCategoryOnly = ticket(
        'Boîtier cassé',
        'Capot plastique abîmé',
        new DateTimeImmutable('2026-09-20 13:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($hardwareCategory);
    $oldKeywordOnly = ticket(
        'Ancien incident',
        'Le terme ancienmot est présent',
        new DateTimeImmutable('2026-09-20 14:00:00'),
    )
        ->setCreatedBy($client)
        ->setCategory($marketingCategory);

    foreach (
        [
            $technician,
            $client,
            $networkCategory,
            $hardwareCategory,
            $printCategory,
            $marketingCategory,
            $current,
            $relevantScreen,
            $printerFalsePositive,
            $wifiFalsePositive,
            $marketingFalsePositive,
            $sameCategoryOnly,
            $oldKeywordOnly,
        ] as $entity
    ) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $sameAnalysisDate = new DateTimeImmutable('2026-09-20 15:00:00');
    $oldAnalysis = analysis($current, ['ancienmot'], $sameAnalysisDate);
    $latestAnalysis = analysis($current, ['écran noir', 'démarrage', 'câble vidéo', 'matériel'], $sameAnalysisDate);
    $entityManager->persist($oldAnalysis);
    $entityManager->persist($latestAnalysis);
    $entityManager->flush();

    ensureSimilarity($latestAnalysis === $analysisRepository->findLatestForTicket($current), 'Latest analysis must use createdAt DESC then id DESC.');
    ensureSimilarity(
        6 === $finder->calculateScore($current, $sameCategoryOnly, []),
        'A shared non-null category must add exactly 6.',
    );

    $currentBefore = serialize($current);
    $similar = $finder->findSimilar($current, $technician);
    ensureSimilarity(!in_array($current, $similar, true), 'The current ticket must always be excluded.');
    ensureSimilarity(in_array($relevantScreen, $similar, true), 'The relevant screen/material ticket must be returned for the screen scenario.');
    ensureSimilarity(!in_array($printerFalsePositive, $similar, true), 'Printer ticket must be excluded as a former false positive.');
    ensureSimilarity(!in_array($wifiFalsePositive, $similar, true), 'Wi-Fi ticket must be excluded as a former false positive.');
    ensureSimilarity(!in_array($marketingFalsePositive, $similar, true), 'Growth marketing ticket must be excluded as a former false positive.');
    ensureSimilarity(!in_array($sameCategoryOnly, $similar, true), 'Same category alone must stay below the minimum score.');
    ensureSimilarity(!in_array($oldKeywordOnly, $similar, true), 'An older AI keyword must not be used.');
    ensureSimilarity($currentBefore === serialize($current), 'Similarity search mutated the current ticket.');

    $keywordCurrent = ticket('Connexion VPN impossible', 'Erreur réseau au bureau', new DateTimeImmutable('2026-09-21 08:00:00'))
        ->setCreatedBy($client)
        ->setCategory($networkCategory);
    $keywordCandidate = ticket('Certificat expiré', 'Le tunnel VPN échoue', new DateTimeImmutable('2026-09-21 09:00:00'))
        ->setCreatedBy($client)
        ->setCategory($marketingCategory);
    $descriptionOnlyWeak = ticket('Incident distinct', 'Connexion lente seulement dans la description', new DateTimeImmutable('2026-09-21 10:00:00'))
        ->setCreatedBy($client)
        ->setCategory($marketingCategory);
    $stopWordsOnly = ticket('Autre demande', 'Bonjour merci déjà mais avec sans pour dans depuis autre reste vérifié vérifier être avoir faire plus très bien problème ticket demande', new DateTimeImmutable('2026-09-21 11:00:00'))
        ->setCreatedBy($client)
        ->setCategory($marketingCategory);
    $entityManager->persist($keywordCurrent);
    $entityManager->persist($keywordCandidate);
    $entityManager->persist($descriptionOnlyWeak);
    $entityManager->persist($stopWordsOnly);
    $entityManager->flush();
    $entityManager->persist(analysis($keywordCurrent, ['certificat expiré'], new DateTimeImmutable('2026-09-21 12:00:00')));
    $entityManager->flush();

    $keywordResults = $finder->findSimilar($keywordCurrent, $technician);
    ensureSimilarity(in_array($keywordCandidate, $keywordResults, true), 'AI keyword phrase and terms must favor a relevant candidate.');
    ensureSimilarity(!in_array($descriptionOnlyWeak, $keywordResults, true), 'A weak description-only match must stay below the minimum score.');
    ensureSimilarity(!in_array($stopWordsOnly, $keywordResults, true), 'Stop words must not produce similar tickets.');

    $shortCurrent = ticket('VPN', 'DNS', new DateTimeImmutable('2026-09-22 08:00:00'))->setCreatedBy($client);
    $shortExactTitle = ticket(' vpn ', 'SSH', new DateTimeImmutable('2026-09-22 09:00:00'))->setCreatedBy($client);
    $entityManager->persist($shortCurrent);
    $entityManager->persist($shortExactTitle);
    $entityManager->flush();
    ensureSimilarity(
        in_array($shortExactTitle, $finder->findSimilar($shortCurrent, $technician), true),
        'An exact normalized short title must reach scoring even without category or four-character terms.',
    );

    try {
        $finder->findSimilar($current, $client);
        throw new RuntimeException('A client must not be allowed to search similar tickets.');
    } catch (AccessDeniedException) {
    }

    $limitCategory = (new Category())->setName('Limite '.bin2hex(random_bytes(4)));
    $limitCurrent = ticket('Routeur principal', 'Signal réseau stable', new DateTimeImmutable('2026-09-23 08:00:00'))
        ->setCreatedBy($client)
        ->setCategory($limitCategory);
    $entityManager->persist($limitCategory);
    $entityManager->persist($limitCurrent);

    $expectedNewest = [];
    $candidateBaseDate = new DateTimeImmutable('2026-09-23 09:00:00');
    for ($index = 1; $index <= 51; ++$index) {
        $candidateDate = $candidateBaseDate->modify(sprintf('+%d minutes', $index >= 50 ? 51 : $index));
        $candidate = ticket(
            'Routeur candidat '.$index,
            'Signal différent '.$index,
            $candidateDate,
        )
            ->setCreatedBy($client)
            ->setCategory($limitCategory);
        $entityManager->persist($candidate);
        $expectedNewest[] = $candidate;
    }
    $entityManager->flush();

    $limitedResults = $finder->findSimilar($limitCurrent, $technician);
    ensureSimilarity(3 === count($limitedResults), 'At most three similar tickets must be returned.');
    ensureSimilarity(
        array_reverse(array_slice($expectedNewest, -3)) === $limitedResults,
        'Equal scores must be ordered by createdAt DESC then id DESC.',
    );
    ensureSimilarity(
        50 === count($ticketRepository->findSimilarityCandidates($limitCurrent, [], 'routeur principal', 100)),
        'Candidate prefilter must enforce its hard limit of 50.',
    );

    $uncategorizedCurrent = ticket('Écran scintille', 'Affichage instable sur moniteur externe', new DateTimeImmutable('2026-09-24 08:00:00'))
        ->setCreatedBy($client);
    $uncategorizedCandidate = ticket('Affichage écran instable', 'Moniteur externe avec écran qui scintille', new DateTimeImmutable('2026-09-24 09:00:00'))
        ->setCreatedBy($client);
    $uncategorizedUnrelated = ticket('Téléphone muet', 'Sonnerie absente', new DateTimeImmutable('2026-09-24 10:00:00'))
        ->setCreatedBy($client);
    $entityManager->persist($uncategorizedCurrent);
    $entityManager->persist($uncategorizedCandidate);
    $entityManager->persist($uncategorizedUnrelated);
    $entityManager->flush();

    ensureSimilarity(
        in_array($uncategorizedCandidate, $finder->findSimilar($uncategorizedCurrent, $technician), true),
        'An uncategorized ticket must still use strict title and description signals.',
    );

    $belowThresholdCategory = (new Category())->setName('Seuil '.bin2hex(random_bytes(4)));
    $belowThresholdCurrent = ticket('Batterie portable', 'Autonomie faible', new DateTimeImmutable('2026-09-25 08:00:00'))
        ->setCreatedBy($client)
        ->setCategory($belowThresholdCategory);
    $belowThresholdCandidate = ticket('Chargeur station', 'Câble secteur', new DateTimeImmutable('2026-09-25 09:00:00'))
        ->setCreatedBy($client)
        ->setCategory($belowThresholdCategory);
    $entityManager->persist($belowThresholdCategory);
    $entityManager->persist($belowThresholdCurrent);
    $entityManager->persist($belowThresholdCandidate);
    $entityManager->flush();

    ensureSimilarity(
        [] === $finder->findSimilar($belowThresholdCurrent, $technician),
        'A score below seven must not produce any result.',
    );
} finally {
    $entityManager->clear();
    $connection->rollBack();
}

echo "SimilarTicketFinder tests: PASS\n";
