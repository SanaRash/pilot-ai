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
- [x] TASK-3.5 — Assignation d'un ticket à un technicien
- [x] TASK-3.6 — Modifier le statut d'un ticket
- [x] TASK-3.7 — Modifier la priorité d'un ticket

## Tâches validées

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

### TASK-3.5 — Assignation

Critères d'acceptation :
- [x] technicien peut prendre en charge un ticket
- [x] assignedTo = technicien connecté
- [x] contrôle d'autorisation
- [x] protection CSRF
- [x] un ticket déjà assigné n'est pas écrasé
- [x] technicien assigné affiché sur le détail
- [x] QA approuvée
- [x] Security Review approuvée
- [x] Code Review approuvée
- [x] validation humaine

### TASK-3.6 — Statut

Critères d'acceptation :
- [x] OPEN
- [x] IN_PROGRESS
- [x] RESOLVED
- [x] CLOSED
- [x] validation serveur
- [x] modification réservée au technicien assigné
- [x] protection CSRF
- [x] aucune priorité modifiée
- [x] aucun TicketHistory créé
- [x] QA approuvée
- [x] Security Review approuvée
- [x] Code Review approuvée
- [x] validation humaine

### TASK-3.7 — Priorité

Critères d'acceptation :
- [x] LOW
- [x] MEDIUM
- [x] HIGH
- [x] URGENT
- [x] validation serveur
- [x] modification réservée au technicien assigné
- [x] protection CSRF
- [x] aucun statut modifié
- [x] aucun TicketHistory créé
- [x] QA approuvée
- [x] Security Review approuvée
- [x] Code Review approuvée
- [x] validation humaine

## Suite

### TASK-3.8 — Catégorie
- [ ] assigner une Category

### TASK-3.9 — Liste tickets client
- [ ] le client ne voit que ses tickets

### TASK-3.10 — Détail ticket client
- [ ] le client ne voit qu'un ticket dont il est propriétaire
