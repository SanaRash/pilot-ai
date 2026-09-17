# Symfony Validation Pipeline — Pilot AI

Toutes les commandes sont exécutées directement dans WSL depuis la racine `~/projects/pilot-ai`.

## Selon le scope

### PHP modifié
```bash
php -l chemin/du/fichier.php
php bin/console lint:container
```

### Twig modifié
```bash
php bin/console lint:twig templates/
```

### Routes
```bash
php bin/console debug:router
```

### Doctrine / migration
```bash
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status
```

### YAML
```bash
php bin/console lint:yaml config/
```

### Tests
Si PHPUnit est configuré et pertinent :
```bash
php bin/phpunit
```

## Rapport

Toujours indiquer :
- commandes lancées ;
- résultat ;
- validations non lancées ;
- raison.
