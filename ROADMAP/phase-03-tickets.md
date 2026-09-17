# Phase 03 — Flux Tickets MVP

## Déjà validé

- [x] TASK-3.1 — Formulaire client de création ticket
- [x] TASK-3.2 — Enregistrement ticket PostgreSQL
  - [x] status = OPEN côté serveur
  - [x] priority = MEDIUM côté serveur
  - [x] source = APP côté serveur
  - [x] createdAt automatique
  - [x] createdBy = utilisateur connecté
- [x] TASK-3.3 — Liste des tickets côté technicien
- [x] TASK-3.4 — Afficher le détail d'un ticket côté technicien

## Tâche validée

### TASK-3.4 — Afficher le détail d'un ticket côté technicien

Critères d'acceptation :
- [x] route `/technician/tickets/{id}`
- [x] ticket chargé via Doctrine
- [x] titre affiché
- [x] description affichée
- [x] client affiché
- [x] statut affiché
- [x] priorité affichée
- [x] source affichée
- [x] date de création affichée
- [x] lien depuis la liste technicien
- [x] accès réservé ROLE_TECHNICIAN
- [x] Twig valide
- [x] QA approuvée
- [x] validation humaine

## Suite

### TASK-3.5 — Assignation
- [ ] technicien peut prendre en charge un ticket
- [ ] assignedTo = technicien connecté ou choix autorisé
- [ ] contrôle d'autorisation

### TASK-3.6 — Statut
- [ ] OPEN
- [ ] IN_PROGRESS
- [ ] RESOLVED
- [ ] CLOSED
- [ ] validation serveur

### TASK-3.7 — Priorité
- [ ] LOW
- [ ] MEDIUM
- [ ] HIGH
- [ ] URGENT

### TASK-3.8 — Catégorie
- [ ] assigner une Category

### TASK-3.9 — Liste tickets client
- [ ] le client ne voit que ses tickets

### TASK-3.10 — Détail ticket client
- [ ] le client ne voit qu'un ticket dont il est propriétaire
