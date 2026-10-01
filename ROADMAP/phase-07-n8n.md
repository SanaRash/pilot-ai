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
- [x] tests séparés du workflow n8n et de l'API

## État d'intégration

- **Statut : partielle — le flux automatique e-mail -> ticket n'est pas complet.**
- [ ] Raccord HTTP du workflow n8n vers `POST /api/tickets/email`

La réception/extraction IMAP et l’API d’ingestion sont implémentées et validées
séparément. Le raccord HTTP n8n -> API n’est pas encore implémenté ; le flux
automatique e-mail -> ticket n’est donc pas complet.

La création du ticket, la stratégie `createdBy`, l’analyse IA et la journalisation
mentionnées ci-dessus sont réalisées côté API d’ingestion. Elles ne signifient pas
que le workflow n8n les déclenche.
