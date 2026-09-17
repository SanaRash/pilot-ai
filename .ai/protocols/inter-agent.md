# Inter-Agent Protocol

## Statuts autorisés

- READY
- IN_PROGRESS
- BLOCKED
- CHANGES_REQUIRED
- APPROVED
- FAILED

## Sévérités

- BLOCKER
- CRITICAL
- MAJOR
- MINOR
- SUGGESTION

BLOCKER, CRITICAL et MAJOR déclenchent une correction obligatoire.

## Format de sortie

```text
STATUS: READY|IN_PROGRESS|BLOCKED|CHANGES_REQUIRED|APPROVED|FAILED

FINDINGS:
- [ISSUE-ID][SEVERITY] fichier:ligne - constat

DECISION:
- décision ou recommandation principale

ACTIONS:
- action réalisée ou demandée

BLOCKERS:
- blocage restant ou "None"

VALIDATION:
- contrôles effectués, résultat, limites
```

## Boucle de correction

1. Reviewer retourne `CHANGES_REQUIRED`.
2. Orchestrator route vers le writer concerné.
3. Writer corrige uniquement le finding et ses impacts directs.
4. Reviewer revalide.
5. Après 3 cycles sur le même identifiant, arrêt et demande d'arbitrage humain.
