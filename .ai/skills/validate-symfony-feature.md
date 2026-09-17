# Skill — Validate Symfony Feature

Selon le changement :

```bash
php -l fichier.php
php bin/console lint:container
php bin/console lint:twig templates/
php bin/console debug:router
php bin/console doctrine:schema:validate
```

Puis vérifier manuellement le scénario utilisateur et les permissions.
