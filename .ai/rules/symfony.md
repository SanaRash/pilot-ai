# Symfony Rules

- Préférer les composants Symfony natifs.
- Contrôleurs fins.
- Services pour logique réutilisable.
- Forms pour saisie structurée.
- Doctrine Repository pour accès aux données.
- Twig sans logique métier.
- Attributs de route cohérents avec le projet.
- Les valeurs système sont définies côté serveur.
- Utiliser migrations Doctrine pour le schéma.
- Ne jamais utiliser `doctrine:schema:update --force`.
- Vérifier les relations et nullability avant migration.
