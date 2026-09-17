# Pilot AI — Multi-Agent Orchestrator

Point d'entrée du système d'orchestration IA du projet Pilot AI.

## Démarrage obligatoire

1. Lire `.ai/protocols/inter-agent.md`.
2. Lire `.ai/protocols/task-state.md`.
3. Lire `.ai/workflows/classification.md`.
4. Lire `ROADMAP/README.md` puis uniquement la phase active.
5. Classifier la tâche : type, complexité, risques, agents requis.
6. Inspecter le repository avant toute création : search -> understand -> reuse -> modify -> create.
7. Lire uniquement les agents, workflows, règles, standards et skills nécessaires.
8. Travailler sur UNE SEULE tâche de roadmap à la fois.
9. Valider avec `.ai/standards/definition-of-done.md` et `.ai/standards/symfony-validation.md`.
10. Attendre la validation humaine avant de cocher la tâche et de proposer le commit.

## Architecture du projet

- Symfony 8 / PHP 8.4 sous WSL Ubuntu.
- PostgreSQL 17 dans Docker Desktop.
- Doctrine ORM.
- Twig + Symfony Forms + Symfony Security.
- n8n plus tard pour l'ingestion e-mail et les automatisations.
- IA assistive via provider interchangeable plus tard.

## Commandes

Les commandes PHP/Symfony sont exécutées directement dans WSL :

```bash
php bin/console ...
php -l fichier.php
```

PostgreSQL reste dans Docker.

Ne pas reprendre les commandes Docker du projet Airbnb.

## Règles globales

- Une tâche métier à la fois.
- Un seul writer actif par fichier et par phase.
- Les reviewers ne valident jamais leur propre implémentation.
- `authenticated != authorized`.
- Ne jamais exposer `ROLE_ADMIN` dans un formulaire public.
- Ne jamais stocker un mot de passe en clair.
- Ne jamais utiliser `doctrine:schema:update --force`.
- Ne jamais supprimer une migration déjà exécutée.
- Ne jamais supprimer automatiquement l'historique métier.
- L'IA reste assistive : elle propose, l'humain valide.
- Maximum 3 cycles automatiques de correction pour un même finding.
- Aucun changement hors scope sans signalement explicite.

## État actuel du MVP

Déjà réalisé :
- environnement WSL, Symfony, Docker, PostgreSQL, Doctrine ;
- entités User, Category, Ticket, Intervention, AIAnalysis, TicketHistory ;
- login / register ;
- rôles CLIENT / TECHNICIAN / ADMIN ;
- routes protégées `/client`, `/technician`, `/admin` ;
- création d'un ticket client ;
- visualisation de la liste des tickets par le technicien.

Prochaine tâche :
- `TASK-3.4` : détail d'un ticket côté technicien.
