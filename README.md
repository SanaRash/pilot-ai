# Pilot AI

Pilot AI est un MVP d’assistance aux équipes techniques et support. Il centralise
les tickets, facilite leur traitement, propose une assistance IA et prépare
l’automatisation de certaines entrées comme l’e-mail. Les interventions et les
événements métier du ticket sont conservés dans son historique.

Pilot AI est un projet MVP et ne remplace pas une solution complète de gestion de
support ou de parc telle que GLPI ou Jira.

## Fonctionnalités MVP

### Client

- inscription et connexion ;
- tableau de bord ;
- création d’un ticket ;
- liste et détail limités aux tickets du client connecté.

### Technicien

- tableau de bord, tickets ouverts et tickets assignés ;
- prise en charge d’un ticket ;
- modification du statut, de la priorité et de la catégorie selon les autorisations
  du technicien assigné ;
- ajout d’interventions ;
- consultation de l’historique ;
- consultation en lecture seule de l’analyse IA et des tickets similaires.

### Administrateur

- tableau de bord ;
- listes des utilisateurs et des catégories ;
- statistiques MVP.

L’espace Admin actuel est en lecture seule. Il ne constitue pas un CRUD Admin complet.

## Architecture technique

- Symfony 8 et PHP >= 8.4 ;
- Doctrine ORM et PostgreSQL 17 ;
- Twig pour les pages web ;
- Docker / Docker Compose pour PostgreSQL en local ;
- OpenRouter comme provider IA implémenté ;
- n8n pour le workflow de réception e-mail IMAP ;
- Git pour le versionnement.

Les principales entités sont `User`, `Ticket`, `Category`, `Intervention`,
`TicketHistory` et `AIAnalysis`. Le code est organisé autour des contrôleurs,
formulaires, repositories et services Symfony, des composants IA, de la sécurité,
des templates Twig, des migrations Doctrine, du workflow n8n et des tests.

## Intelligence artificielle

Le provider OpenRouter implémenté peut produire une analyse de ticket comprenant :

- un résumé (`summary`) ;
- une priorité suggérée (`suggestedPriority`) ;
- une catégorie suggérée (`suggestedCategory`) ;
- des mots-clés (`keywords`) ;
- des suggestions (`suggestions`).

Le technicien peut consulter l’analyse et les tickets similaires. Dans le flux
actuellement implémenté, l’analyse est tentée après l’ingestion d’un ticket par l’API
e-mail. Les recommandations restent assistives : elles ne changent automatiquement
ni le statut, ni la priorité, ni la catégorie, ni l’assignation et ne ferment jamais
un ticket. L’analyse IA dépend de la configuration OpenRouter locale et de la
disponibilité du fournisseur.

## E-mail et n8n — état réel

- **Workflow n8n IMAP :** réception d’e-mail et extraction des champs `sender`,
  `subject` et `content` implémentées.
- **API Pilot AI :** `POST /api/tickets/email` implémentée séparément. Elle utilise
  un jeton d’authentification, valide le payload, crée un ticket `EMAIL` avec un compte système,
  inscrit l’événement `TICKET_CREATED` et tente une analyse IA non bloquante.
- **Raccord n8n → API : non implémenté actuellement.**

> Le workflow n8n et l’API d’ingestion sont fonctionnels séparément. Le nœud HTTP
> reliant n8n à l’API Pilot AI n’est pas encore implémenté dans le MVP actuel.

Le workflow n’envoie donc pas actuellement les e-mails reçus à Pilot AI et ne crée
pas de tickets automatiquement. Les détails IMAP et ses limites sont dans
[n8n/README.md](./n8n/README.md).

## Installation

La procédure complète est dans [INSTALLATION.md](./INSTALLATION.md). En bref, elle
nécessite PHP, Composer, Symfony CLI, Docker et Docker Compose ; configure les
variables locales ; démarre PostgreSQL, applique les migrations et crée les comptes
nécessaires.

Les véritables valeurs de configuration sont locales : voir
[Sécurité et configuration](#sécurité-et-configuration).

## Tests et validations

Les tests du dépôt sont des scripts PHP exécutés directement ; PHPUnit n’est pas
déclaré comme dépendance de ce projet. Les familles couvertes comprennent :

- sécurité et matrice d’accès ;
- parcours E2E Client, Technicien et Admin ;
- ingestion e-mail/API ;
- analyse IA ;
- intégrité Doctrine ;
- smoke tests UI et non-régression.

Exemples de tests :

```bash
php tests/Controller/ClientE2EFlowTest.php
php tests/Controller/TechnicianE2EFlowTest.php
php tests/Controller/AdminE2EFlowTest.php
php tests/Controller/SecurityAccessMatrixTest.php
php tests/Controller/EmailIngestionE2EFlowTest.php
php tests/AI/AIFinalValidationTest.php
php tests/Doctrine/DoctrineIntegrityTest.php
php tests/Controller/UiSmokeTest.php
```

Validations Symfony et Composer :

```bash
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:schema:validate
composer validate --no-check-publish
```

## Scénario de démonstration

Créer au préalable, localement, un compte de chaque rôle avec
[`app:create-user`](./INSTALLATION.md#comptes-utilisateurs). Aucun compte ni mot de
passe de démonstration n’est fourni par le dépôt.

1. Se connecter comme Client, créer un ticket, puis montrer sa liste et son détail.
2. Se connecter comme Technicien, montrer les tickets ouverts, prendre en charge le
   ticket de démonstration et ouvrir son détail.
3. Présenter les actions de statut, priorité et catégorie disponibles pour le
   technicien assigné, puis ajouter une intervention. Pour l’action de catégorie,
   une catégorie doit déjà exister dans la base.
4. Montrer l’historique et, si une analyse existe pour le ticket, son affichage en
   lecture seule ainsi que les tickets similaires. L’analyse IA n’est pas déclenchée
   par la création d’un ticket Client ; ne montrer que le résultat d’un ticket ayant
   déjà une analyse.
5. Se connecter comme Admin et présenter le tableau de bord, les listes
   utilisateurs/catégories et les statistiques, en précisant que ces pages sont en
   lecture seule.
6. Présenter séparément l’API d’ingestion e-mail et sa validation/tests. Ne pas
   laisser entendre qu’un e-mail reçu par n8n crée un ticket : le raccord HTTP n’est
   pas implémenté.
7. Présenter le workflow IMAP comme réception/extraction autonome. Une clé OpenRouter
   n’est pas nécessaire pour montrer les résultats IA déjà persistés ou les scénarios
   simulés par les tests ; si aucun résultat n’est déjà disponible, ne pas dépendre
   d’un appel externe réel et présenter l’IA comme une capacité configurée séparément.

Les captures d’écran de soutenance sont à réaliser manuellement ; elles ne sont pas
fournies ni requises dans ce dépôt.

## Limites du MVP

1. Le raccord HTTP n8n → API d’ingestion n’est pas implémenté.
2. Les pièces jointes des e-mails ne sont ni téléchargées ni traitées par le workflow.
3. Il n’y a pas d’idempotence métier basée sur `messageId`.
4. Le workflow n’ajoute pas de retry applicatif personnalisé.
5. L’ingestion IMAP/API dépend de credentials et de secrets configurés localement.
6. La disponibilité et les quotas OpenRouter ne sont pas garantis par l’application.
7. L’IA est assistive uniquement ; elle ne prend pas de décision métier automatique.
8. L’Admin ne dispose pas d’un CRUD complet.
9. Aucun compte de démonstration permanent ou credential n’est versionné.
10. Le MVP ne comprend pas de RAG ni d’embeddings.

Ces limites décrivent le périmètre actuel ; elles ne sont pas des fonctionnalités
implicitement disponibles.

## Pistes V2 — non implémentées

- connecter le workflow n8n à l’API protégée ;
- ajouter idempotence sur `messageId` ;
- ajouter retry, monitoring et observabilité du flux e-mail ;
- étudier le traitement des pièces jointes ;
- ajouter un RAG ou une base de connaissances ;
- étudier des intégrations GLPI, Jira ou GitHub Issues ;
- compléter l’administration ;
- ajouter des providers IA ;
- améliorer l’observabilité et la gestion des quotas IA ;
- préparer un environnement de déploiement et de démonstration.

## Sécurité et configuration

Les secrets et credentials réels doivent être définis dans `.env.local` ou dans
l’environnement local/déployé. `.env.local` est ignoré par Git. Ne jamais commiter
de secret, mot de passe, clé API ou credential réel.

- `APP_SECRET` : secret Symfony, privé et aléatoire ;
- `DATABASE_URL` : URL de connexion PostgreSQL propre à l’environnement ;
- `OPENROUTER_API_KEY` : clé privée si OpenRouter est utilisé ;
- `PILOTAI_EMAIL_WEBHOOK_SECRET` : secret privé d’authentification de l’API d’ingestion ;
- `PILOTAI_EMAIL_SYSTEM_USER_EMAIL` : adresse configurée pour le compte système
  d’ingestion, non secrète en elle-même ;
- `POSTGRES_PASSWORD` : mot de passe local de PostgreSQL Docker.

La configuration illustrative versionnée n’est pas un jeu d’identifiants utilisable.
La documentation détaillée de configuration est dans
[INSTALLATION.md](./INSTALLATION.md).

## Roadmap

Consulter [ROADMAP/README.md](./ROADMAP/README.md) et les fichiers de phase pour
l’état versionné des tâches. La Phase 09 y reste non cochée et certains items de la
Phase 07 décrivent une création de tickets/appel IA alors que le workflow n8n actuel
ne contient pas de raccord HTTP. Cet écart est signalé, non corrigé ici ; les fichiers
de roadmap restent inchangés et leur arbitrage/clôture suit une validation distincte.
