# Workflow — UI Change

1. Confirmer que le comportement backend existe.
2. Frontend Developer modifie Twig.
3. Ne pas déplacer de logique métier dans Twig.
4. Lancer `php bin/console lint:twig templates/`.
5. QA vérifie affichage, états et accès.
6. Validation humaine.
