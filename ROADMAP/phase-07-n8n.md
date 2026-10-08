# Phase 07 — n8n et ingestion e-mail

- [x] endpoint `POST /api/tickets/email`
- [x] authentification Bearer de l'endpoint
- [x] workflow n8n réception e-mail
- [x] extraction expéditeur
- [x] extraction sujet
- [x] extraction contenu
- [x] création Ticket source EMAIL
- [x] stratégie createdBy pour ingestion système
- [x] appel analyse IA
- [x] journalisation erreurs
- [x] raccord HTTP du workflow n8n vers `POST /api/tickets/email`
- [x] déduplication par `messageId`
- [x] suivi de l’expéditeur réel avec `requesterEmail`
- [x] tests du workflow n8n, de l’API et du flux E2E

## État d'intégration

- **Statut : IMPLEMENTED / VALIDATED pour le MVP.**

La réception/extraction IMAP, le raccord HTTP n8n -> API, la création de ticket
`EMAIL`, le compte système `createdBy`, `requesterEmail`, l’idempotence `messageId`,
l’analyse IA et la catégorisation automatique non bloquante sont implémentés et
validés.

Le test E2E réel n8n -> Symfony a validé le chemin Gmail/IMAP -> n8n -> extraction
`sender`, `subject`, `content`, `messageId` -> `POST /api/tickets/email` -> ticket
Pilot AI. Le rejeu du même `messageId` retourne `duplicate: true` sans second ticket.
