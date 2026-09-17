# Skill — Create Migration

```bash
php bin/console make:migration
```

Puis :
1. ouvrir le fichier généré ;
2. vérifier les ALTER/CREATE/DROP ;
3. ne pas supprimer une migration déjà exécutée ;
4. exécuter :
   `php bin/console doctrine:migrations:migrate`
5. valider :
   `php bin/console doctrine:schema:validate`
