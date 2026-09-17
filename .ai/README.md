# Pilot AI — Système d'orchestration IA

Ce dossier contient les rôles, workflows, protocoles, règles, skills et standards qui guident les agents IA travaillant sur Pilot AI.

## Principe

L'orchestrateur :
1. lit la roadmap ;
2. choisit la première tâche non terminée ;
3. la classifie ;
4. active uniquement les agents nécessaires ;
5. fait implémenter ;
6. fait vérifier ;
7. attend la validation humaine ;
8. met ensuite à jour la roadmap et propose un commit.

Le système est volontairement plus léger que celui du projet Airbnb, mais conserve ses bonnes pratiques.
