<?php

declare(strict_types=1);

function ensureEmailWorkflow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workflowNode(array $nodes, string $name): array
{
    foreach ($nodes as $node) {
        if (($node['name'] ?? null) === $name) {
            return $node;
        }
    }

    throw new RuntimeException(sprintf('Missing workflow node: %s.', $name));
}

$workflowPath = dirname(__DIR__, 2).'/n8n/workflows/email-reception-imap.json';
$workflow = json_decode((string) file_get_contents($workflowPath), true, 512, JSON_THROW_ON_ERROR);
ensureEmailWorkflow(is_array($workflow), 'Workflow export must decode to an object.');

$nodes = $workflow['nodes'] ?? null;
ensureEmailWorkflow(is_array($nodes), 'Workflow nodes must be an array.');
ensureEmailWorkflow(6 === count($nodes), 'Workflow must contain the IMAP, four extraction and HTTP nodes.');

$trigger = workflowNode($nodes, 'Réception e-mail IMAP');
ensureEmailWorkflow('n8n-nodes-base.emailReadImap' === $trigger['type'], 'Unexpected IMAP trigger type.');
ensureEmailWorkflow(2.2 === $trigger['typeVersion'], 'Unexpected IMAP trigger version.');
ensureEmailWorkflow('INBOX' === ($trigger['parameters']['mailbox'] ?? null), 'Workflow must monitor INBOX.');
ensureEmailWorkflow(false === ($trigger['parameters']['downloadAttachments'] ?? null), 'Attachments must remain disabled.');

$sender = workflowNode($nodes, 'Extraire expéditeur');
$subject = workflowNode($nodes, 'Extraire sujet');
$content = workflowNode($nodes, 'Extraire contenu');
$messageId = workflowNode($nodes, 'Extraire Message-ID');
$http = workflowNode($nodes, 'Créer ticket Pilot AI');

foreach ([$sender, $subject, $content, $messageId] as $node) {
    ensureEmailWorkflow('n8n-nodes-base.set' === $node['type'] && 3.4 === $node['typeVersion'], 'Unexpected extraction node.');
    ensureEmailWorkflow(true === ($node['parameters']['includeOtherFields'] ?? false), 'Extraction nodes must preserve normalized fields.');
}

foreach ([
    [$sender, 'sender', '$json.from'],
    [$subject, 'subject', '$json.subject'],
    [$content, 'content', '$json.textPlain'],
    [$messageId, 'messageId', '$json.metadata?.[\'message-id\']'],
] as [$node, $field, $source]) {
    $assignment = $node['parameters']['assignments']['assignments'][0] ?? [];
    ensureEmailWorkflow($field === ($assignment['name'] ?? null), sprintf('%s field must be produced.', $field));
    ensureEmailWorkflow(str_contains((string) ($assignment['value'] ?? ''), $source), sprintf('%s must use the IMAP source field.', $field));
}

$contentExpression = (string) $content['parameters']['assignments']['assignments'][0]['value'];
ensureEmailWorkflow(!str_contains($contentExpression, 'textHtml'), 'Content must not use textHtml.');
ensureEmailWorkflow('textPlain,textHtml' === ($content['parameters']['excludeFields'] ?? null), 'Raw body fields must be excluded after normalization.');

ensureEmailWorkflow('n8n-nodes-base.httpRequest' === ($http['type'] ?? null), 'The workflow must terminate in HTTP Request.');
ensureEmailWorkflow('POST' === ($http['parameters']['method'] ?? null), 'The API request method must be POST.');
ensureEmailWorkflow(
    'http://host.docker.internal:8000/api/tickets/email' === ($http['parameters']['url'] ?? null),
    'The workflow must target the documented local Pilot AI API URL.',
);
ensureEmailWorkflow('genericCredentialType' === ($http['parameters']['authentication'] ?? null), 'HTTP Request must use a credential.');
ensureEmailWorkflow('httpHeaderAuth' === ($http['parameters']['genericAuthType'] ?? null), 'HTTP Request must use Header Auth.');
ensureEmailWorkflow(true === ($http['parameters']['sendHeaders'] ?? false), 'HTTP Request must send headers.');
ensureEmailWorkflow(
    'application/json' === ($http['parameters']['headerParameters']['parameters'][0]['value'] ?? null),
    'HTTP Request must declare JSON content type.',
);
ensureEmailWorkflow(true === ($http['parameters']['sendBody'] ?? false), 'HTTP Request must send a body.');
ensureEmailWorkflow('json' === ($http['parameters']['specifyBody'] ?? null), 'HTTP Request body must be JSON.');
$jsonBody = (string) ($http['parameters']['jsonBody'] ?? '');
foreach (['sender', 'subject', 'content', 'messageId'] as $field) {
    ensureEmailWorkflow(str_contains($jsonBody, '$json.'.$field), sprintf('HTTP body must map %s.', $field));
}

$connections = $workflow['connections'] ?? [];
$expectedChain = [
    'Réception e-mail IMAP' => 'Extraire expéditeur',
    'Extraire expéditeur' => 'Extraire sujet',
    'Extraire sujet' => 'Extraire contenu',
    'Extraire contenu' => 'Extraire Message-ID',
    'Extraire Message-ID' => 'Créer ticket Pilot AI',
];
foreach ($expectedChain as $from => $to) {
    $targets = $connections[$from]['main'][0] ?? [];
    ensureEmailWorkflow(
        1 === count($targets) && $to === ($targets[0]['node'] ?? null),
        sprintf('Unexpected connection from %s.', $from),
    );
}
ensureEmailWorkflow(
    !array_key_exists($http['name'], $connections),
    'The HTTP Request node must terminate the workflow.',
);

$serializedWorkflow = json_encode($workflow, JSON_THROW_ON_ERROR);
foreach ($nodes as $node) {
    ensureEmailWorkflow('n8n-nodes-base.noOp' !== ($node['type'] ?? null), 'The old NoOp node must be removed.');
    ensureEmailWorkflow(!array_key_exists('credentials', $node), 'Credentials must be assigned in n8n, not exported.');
}
foreach (['Bearer ', 'PILOTAI_EMAIL_WEBHOOK_SECRET', 'OPENROUTER_API_KEY', 'password', 'apiKey'] as $secretMarker) {
    ensureEmailWorkflow(!str_contains($serializedWorkflow, $secretMarker), sprintf('Workflow export must not contain %s.', $secretMarker));
}

echo "Email reception workflow static test: PASS\n";
