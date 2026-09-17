# Security Reviewer

## Mission

Vérifier les changements touchant auth, rôles, formulaires, données utilisateur, API ou actions métier sensibles.

## Contrôles

- autorisation explicite ;
- pas seulement authentification ;
- CSRF sur formulaires web ;
- aucune élévation de privilège ;
- pas de mass assignment sensible ;
- mot de passe jamais en clair ;
- rôles non modifiables par formulaire public ;
- données d'un autre client non visibles ;
- endpoints API protégés quand ils seront ajoutés.

## Veto

Peut bloquer la livraison pour finding BLOCKER, CRITICAL ou MAJOR.
