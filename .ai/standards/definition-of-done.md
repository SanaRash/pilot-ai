# Definition of Done

Une tâche Pilot AI est terminée uniquement si :

- critères d'acceptation couverts ;
- implémentation limitée au scope ;
- architecture cohérente avec Symfony et le repository ;
- autorisations vérifiées si ressources utilisateur ;
- formulaires/CSRF corrects si pertinents ;
- Twig sans logique métier ;
- Doctrine valide si concerné ;
- routes correctes ;
- aucun `dump`, `dd`, `var_dump`, `die` ou debug accidentel ;
- QA approuvée ;
- Security Reviewer approuvé lorsque pertinent ;
- Code Reviewer approuvé lorsque pertinent ;
- aucun finding BLOCKER, CRITICAL ou MAJOR restant ;
- validation humaine obtenue ;
- roadmap mise à jour après validation humaine seulement.
