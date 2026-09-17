# Skill — Create or Extend Entity

Commande :
```bash
php bin/console make:entity NomEntite
```

Avant :
- vérifier MPD et relations existantes.

Après :
- contrôler type, length, nullable, cardinalité ;
- générer migration ;
- inspecter migration ;
- migrer ;
- `doctrine:schema:validate`.
