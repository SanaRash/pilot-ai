# Task State Protocol

Le Task State reste volatile sauf demande explicite.

```yaml
task: ""
roadmap_id: ""
type: FEATURE|BUG|REFACTOR|DATABASE|SECURITY|UI|INFRASTRUCTURE|DOCUMENTATION|TEST
complexity: TRIVIAL|STANDARD|COMPLEX|CRITICAL
risk: []
current_phase: DISCOVERY|REQUIREMENTS|PLANNING|IMPLEMENTATION|REVIEW|VALIDATION|DONE|BLOCKED|FAILED

requirements_status: NOT_STARTED|IN_PROGRESS|APPROVED|BLOCKED|FAILED
plan_status: NOT_STARTED|IN_PROGRESS|APPROVED|BLOCKED|FAILED

active_agents: {}
completed_agents: {}
file_ownership: {}
correction_cycles: {}
vetoes: {}
validations: {}
out_of_scope_findings: []

human_validation: PENDING|APPROVED|REJECTED
final_status: IN_PROGRESS|APPROVED|BLOCKED|FAILED
```

## Règle Pilot AI

Une tâche ne passe à `DONE` qu'après validation humaine.
