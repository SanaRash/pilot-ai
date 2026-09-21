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
ensureEmailWorkflow(5 === count($nodes), 'Workflow must contain exactly five nodes.');

$trigger = workflowNode($nodes, 'Réception e-mail IMAP');
ensureEmailWorkflow('n8n-nodes-base.emailReadImap' === $trigger['type'], 'Unexpected IMAP trigger type.');
ensureEmailWorkflow(2.2 === $trigger['typeVersion'], 'Unexpected IMAP trigger version.');
ensureEmailWorkflow('INBOX' === ($trigger['parameters']['mailbox'] ?? null), 'Workflow must monitor INBOX.');
ensureEmailWorkflow(false === ($trigger['parameters']['downloadAttachments'] ?? null), 'Attachments must remain disabled.');
ensureEmailWorkflow(!array_key_exists('credentials', $trigger), 'Workflow must not embed credentials.');

$sender = workflowNode($nodes, 'Extraire expéditeur');
$subject = workflowNode($nodes, 'Extraire sujet');
$content = workflowNode($nodes, 'Extraire contenu');
$sink = workflowNode($nodes, 'Contrôle réception uniquement');

ensureEmailWorkflow('n8n-nodes-base.set' === $sender['type'] && 3.4 === $sender['typeVersion'], 'Unexpected sender extraction node.');
ensureEmailWorkflow('n8n-nodes-base.set' === $subject['type'] && 3.4 === $subject['typeVersion'], 'Unexpected subject extraction node.');
ensureEmailWorkflow('n8n-nodes-base.set' === $content['type'] && 3.4 === $content['typeVersion'], 'Unexpected content extraction node.');
ensureEmailWorkflow('n8n-nodes-base.noOp' === $sink['type'] && 1 === $sink['typeVersion'], 'Unexpected workflow sink node.');

foreach ([$sender, $subject, $content] as $node) {
    ensureEmailWorkflow(true === ($node['parameters']['includeOtherFields'] ?? false), 'Extraction nodes must preserve other fields.');
}

$senderExpression = (string) ($sender['parameters']['assignments']['assignments'][0]['value'] ?? '');
$subjectExpression = (string) ($subject['parameters']['assignments']['assignments'][0]['value'] ?? '');
$contentExpression = (string) ($content['parameters']['assignments']['assignments'][0]['value'] ?? '');
ensureEmailWorkflow('sender' === ($sender['parameters']['assignments']['assignments'][0]['name'] ?? null), 'Sender field must be produced.');
ensureEmailWorkflow('subject' === ($subject['parameters']['assignments']['assignments'][0]['name'] ?? null), 'Subject field must be produced.');
ensureEmailWorkflow('content' === ($content['parameters']['assignments']['assignments'][0]['name'] ?? null), 'Content field must be produced.');
ensureEmailWorkflow(str_contains($senderExpression, '$json.from'), 'Sender must be based on the message sender.');
ensureEmailWorkflow(str_contains($subjectExpression, '$json.subject'), 'Subject must be based on the message subject.');
ensureEmailWorkflow(str_contains($contentExpression, '$json.textPlain'), 'Content must be based on textPlain.');
ensureEmailWorkflow(!str_contains($contentExpression, 'textHtml'), 'Content must not use textHtml.');
ensureEmailWorkflow('textPlain,textHtml' === ($content['parameters']['excludeFields'] ?? null), 'Raw body fields must be excluded after normalization.');

$connections = $workflow['connections'] ?? [];
$expectedChain = [
    'Réception e-mail IMAP' => 'Extraire expéditeur',
    'Extraire expéditeur' => 'Extraire sujet',
    'Extraire sujet' => 'Extraire contenu',
    'Extraire contenu' => 'Contrôle réception uniquement',
];
foreach ($expectedChain as $from => $to) {
    $targets = $connections[$from]['main'][0] ?? [];
    ensureEmailWorkflow(
        1 === count($targets) && $to === ($targets[0]['node'] ?? null),
        sprintf('Unexpected connection from %s.', $from),
    );
}
ensureEmailWorkflow([] === ($connections[$sink['name']] ?? []), 'The control node must terminate the workflow.');

foreach ($nodes as $node) {
    ensureEmailWorkflow('n8n-nodes-base.httpRequest' !== ($node['type'] ?? null), 'Workflow must not contain HTTP Request.');
    ensureEmailWorkflow(!array_key_exists('credentials', $node), 'Workflow must not embed node credentials.');
}

$serializedWorkflow = json_encode($workflow, JSON_THROW_ON_ERROR);
ensureEmailWorkflow(!str_contains($serializedWorkflow, '/api/tickets/email'), 'Workflow must not call the email API.');

echo "Email reception workflow static test: PASS\n";
