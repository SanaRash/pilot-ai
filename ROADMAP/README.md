# Pilot AI — Roadmap

## Règles

- Une seule tâche active à la fois.
- Une case est cochée uniquement après implémentation, QA et validation humaine.
- Ne pas ajouter de champ ou relation hors modèle sans arbitrage explicite.
- Ne pas passer à une phase suivante tant que le flux courant n'est pas suffisamment stable.

## Phases

- [x] [Phase 01 — Socle technique](phase-01-socle-technique.md)
- [x] [Phase 02 — Authentification et rôles](phase-02-authentification-roles.md)
- [x] [Phase 03 — Flux Tickets MVP](phase-03-tickets.md)
- [x] [Phase 04 — Interventions](phase-04-interventions.md)
- [x] [Phase 05 — Historique](phase-05-historique.md)
- [x] [Phase 06 — Intelligence artificielle](phase-06-ia.md)
- [ ] [Phase 07 — n8n et ingestion e-mail](phase-07-n8n.md)
- [x] [Phase 08 — Dashboards et UI](phase-08-dashboard-ui.md)
- [x] [Phase 09 — Tests, sécurité et livraison](phase-09-tests-livraison.md)

## État final du MVP

Le MVP Pilot AI est fonctionnel et validé sur les parcours Client, Technicien, Admin,
IA, sécurité, intégrité Doctrine et UI. La Phase 07 reste partielle : le workflow n8n
reçoit et extrait les e-mails et l’API d’ingestion existe, mais le raccord HTTP n8n ->
API reste à implémenter.

Les Phases 01 à 06 et 08 à 09 sont terminées. La Phase 07 ne doit pas être considérée
comme un flux e-mail -> ticket automatique complet.

## État de la roadmap

La mise en cohérence de la roadmap n’ajoute pas de fonctionnalité et ne marque pas le
raccord n8n -> API comme réalisé. Les évolutions restantes de cette intégration sont
à traiter séparément.
