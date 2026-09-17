# Workflow — Database Change

1. Confirmer que le changement est nécessaire au MVP.
2. Database Expert analyse entités et relations.
3. Symfony Developer modifie l'entité.
4. Générer une migration :
   `php bin/console make:migration`
5. Inspecter le fichier de migration.
6. Appliquer seulement après vérification :
   `php bin/console doctrine:migrations:migrate`
7. Valider :
   `php bin/console doctrine:schema:validate`
8. QA.
9. Validation humaine.
10. Roadmap + commit.

## Interdictions

- `doctrine:schema:update --force`
- suppression d'une migration déjà exécutée
- drop de table hors demande explicite
