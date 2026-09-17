# Task Classification

## Types

- FEATURE : nouveau comportement utilisateur ou métier.
- BUG : comportement incorrect.
- REFACTOR : changement interne sans nouveau comportement.
- DATABASE : entité, migration, relation, query.
- SECURITY : auth, rôle, accès, secret, CSRF, API.
- UI : Twig, mise en page, responsive.
- INFRASTRUCTURE : WSL, Docker, env, n8n runtime.
- DOCUMENTATION : documentation uniquement.
- TEST : tests uniquement.

## Complexités

- TRIVIAL : 1 fichier, faible risque.
- STANDARD : quelques fichiers cohérents.
- COMPLEX : plusieurs domaines ou fortes dépendances.
- CRITICAL : auth, migration risquée, suppression, secrets, intégrité sensible.

## Sélection rapide

| Demande | Workflow | Agents minimaux |
| --- | --- | --- |
| Détail ticket technicien | feature | Requirement Analyst, Symfony Developer, Frontend, QA, Code Reviewer |
| Nouvelle relation Doctrine | database-change | Database Expert, Symfony Developer, QA, Code Reviewer |
| Contrôle d'accès | security-fix | Security Reviewer, Symfony Developer, QA, Code Reviewer |
| Correction comportement | bugfix | Symfony Developer, QA, Code Reviewer |
| Modification Twig seulement | ui-change | Frontend, QA léger |
| Refactor service | refactoring | Symfony Developer, QA, Code Reviewer |
