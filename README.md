# Pilot AI

Pilot AI est une application de gestion de tickets IT assistée par IA. Le MVP
centralise les demandes de support, aide les techniciens à les qualifier et propose
des recommandations sans remplacer la validation humaine.

Pilot AI reste un MVP : il ne remplace pas une solution complète de support ou de
parc comme GLPI, Jira Service Management ou un outil ITSM complet.

## Rôles

- **Client** : crée et suit ses demandes, consulte les interventions visibles et
  utilise l’assistant flottant.
- **Technicien** : consulte les tickets, prend en charge, modifie les informations
  métier autorisées, ajoute des interventions et consulte l’IA.
- **Administrateur** : consulte le tableau de bord, les utilisateurs, les catégories,
  les statistiques et les tickets en lecture seule.

## Fonctionnalités MVP

### Client

- inscription et connexion ;
- tableau de bord ;
- création de ticket ;
- liste et détail limités aux tickets du client connecté ;
- consultation des interventions rendues visibles au client ;
- assistant flottant avec base de connaissances ;
- mémoire courte de conversation en `sessionStorage` ;
- bouton “Créer une demande” depuis l’assistant ;
- formulaire de ticket prérempli à partir du brouillon assistant.

### Technicien

- tableau de bord ;
- tickets ouverts et tickets assignés ;
- prise en charge d’un ticket ;
- modification du statut, de la priorité et de la catégorie selon les règles
  existantes ;
- ajout d’interventions ;
- consultation de l’historique ;
- consultation en lecture seule des analyses IA, suggestions et tickets similaires ;
- affichage de l’expéditeur e-mail réel sur les tickets créés par ingestion.

### Administrateur

- tableau de bord ;
- liste des utilisateurs en lecture seule ;
- liste des catégories en lecture seule ;
- statistiques MVP ;
- détail ticket en lecture seule, incluant l’analyse IA et l’expéditeur e-mail si
  le ticket provient de l’ingestion.

### E-mail et n8n

- réception Gmail/IMAP via n8n ;
- extraction `sender`, `subject`, `content` et `messageId` ;
- appel HTTP vers `POST /api/tickets/email` ;
- authentification Bearer ;
- création de ticket `EMAIL` avec compte système ;
- conservation de l’adresse réelle dans `requesterEmail` ;
- déduplication par `messageId` ;
- tentative d’analyse IA et catégorisation automatique non bloquantes.

## Stack technique

- Symfony 8 ;
- PHP 8.4 ;
- PostgreSQL 17 ;
- Docker / Docker Compose ;
- Doctrine ORM et Doctrine Migrations ;
- Twig, HTML et JavaScript léger ;
- n8n pour la réception e-mail IMAP ;
- OpenRouter comme provider IA.

Aucune dépendance lourde de type RAG, embeddings, base vectorielle ou WebSocket
n’est utilisée dans le MVP.

## Architecture courte

```text
Client Web
    |
Symfony
 |      \
 |       OpenRouter
 |
PostgreSQL
 ^
 |
n8n <- IMAP/Gmail
```

Flux IA principaux :

```text
Ticket classique ou e-mail
→ AIAnalysis
→ catégorie suggérée parmi les catégories existantes
→ catégorisation automatique uniquement si le ticket n’a pas déjà de catégorie
```

```text
Assistant client
→ KnowledgeSearchService
→ OpenRouter
→ réponse structurée / escalade vers création de demande
```

## Installation rapide

La procédure détaillée est dans [INSTALLATION.md](./INSTALLATION.md). Les commandes
principales sont :

```bash
composer install
docker compose --env-file .env.local up -d
php bin/console doctrine:migrations:migrate
symfony server:start --listen-ip=0.0.0.0 --port=8000 --no-tls
```

Les secrets et paramètres locaux doivent rester dans `.env.local`, jamais dans Git.

Variables locales typiques :

```dotenv
APP_SECRET=<secret-local-aleatoire>
POSTGRES_PASSWORD=<mot-de-passe-local>
DATABASE_URL="postgresql://pilot_ai:<mot-de-passe-url-encode>@127.0.0.1:5432/pilot_ai?serverVersion=17&charset=utf8"
OPENROUTER_API_KEY=
OPENROUTER_MODEL=
PILOTAI_EMAIL_WEBHOOK_SECRET=
PILOTAI_EMAIL_SYSTEM_USER_EMAIL=email-ingestion@pilot-ai.internal
```

`OPENROUTER_API_KEY` contient la clé privée du provider IA. `OPENROUTER_MODEL`
désigne le modèle OpenRouter utilisé pour l’analyse ticket et l’assistant client.
`PILOTAI_EMAIL_WEBHOOK_SECRET` protège l’API d’ingestion e-mail. Les vraies valeurs
ne doivent jamais être versionnées.

## Données de démonstration

Deux scripts SQL de démonstration sont fournis :

- `pilot_ai_demo_seed.sql` : catégories, tickets, historiques, interventions et
  analyses IA de démonstration ;
- `knowledge_articles_demo.sql` : articles de base de connaissances client-safe pour
  l’assistant flottant.

Catégories attendues pour la démo :

- Impression ;
- Logiciel ;
- Matériel ;
- Réseau ;
- Téléphonie.

Créer d’abord au moins un compte actif `ROLE_CLIENT`, un compte `ROLE_TECHNICIAN` et
un compte `ROLE_ADMIN` avec :

```bash
php bin/console app:create-user
```

Puis exécuter les scripts localement avec l’outil PostgreSQL convenu. Aucun mot de
passe ou compte de démonstration réel n’est fourni dans le dépôt.

## Assistant client

L’assistant client est un widget flottant visible uniquement côté Client. Il utilise
la base de connaissances locale pour sélectionner au maximum trois articles actifs
et sûrs pour le client, puis appelle OpenRouter.

Comportement actuel :

- historique court stocké côté navigateur en `sessionStorage` ;
- maximum 6 messages conservés ;
- pas de mémoire longue durée ;
- pas de mémoire globale entre utilisateurs ;
- réponse provider structurée avec `answer` et `needsTechnician` ;
- si les connaissances sont insuffisantes, `needsTechnician` permet d’afficher
  “Créer une demande” ;
- le bouton ne crée pas de ticket automatiquement ;
- il prépare un brouillon en session serveur et redirige vers le formulaire existant ;
- le client peut modifier le titre et la description avant validation.

Le chatbot ne crée jamais de ticket tout seul, ne modifie pas de ticket existant et
ne persiste pas une conversation longue durée.

## E-mail / n8n

Le flux validé est :

```text
Gmail/IMAP
→ n8n
→ extraction sender / subject / content / messageId
→ POST /api/tickets/email
→ création ticket EMAIL
→ AIAnalysis
→ catégorisation automatique si possible
```

L’API attend un JSON strict :

```json
{
  "sender": "client@example.com",
  "subject": "Imprimante hors ligne",
  "content": "Bonjour, ...",
  "messageId": "<message-id@example.com>"
}
```

Points importants :

- authentification par `Authorization: Bearer <secret>` ;
- le secret réel est configuré dans `.env.local` ou l’environnement ;
- aucun credential n8n n’est exporté dans le workflow ;
- `messageId` est obligatoire et possède une contrainte unique ;
- première réception : `201` avec `duplicate: false` ;
- rejeu du même `messageId` : `200` avec `duplicate: true` et le même ticket ;
- aucun second ticket, historique ou appel IA n’est créé lors d’un doublon ;
- `createdBy` reste le compte système d’ingestion ;
- `requesterEmail` conserve l’adresse réelle de l’expéditeur.

Créer ou vérifier le compte système avec :

```bash
php bin/console app:provision-email-system-user
```

Pour un n8n en Docker qui appelle Symfony en local, le workflow utilise :

```text
http://host.docker.internal:8000/api/tickets/email
```

Selon l’environnement Docker, ajouter le mapping d’hôte :

```text
--add-host=host.docker.internal:host-gateway
```

ou en Compose :

```yaml
extra_hosts:
  - "host.docker.internal:host-gateway"
```

Voir [n8n/README.md](./n8n/README.md) pour l’import du workflow, les credentials IMAP
et Header Auth, et les tests manuels. Ne jamais copier de Bearer secret dans le
workflow exporté.

## Intelligence artificielle

L’analyse IA d’un ticket produit :

- un résumé ;
- une priorité suggérée ;
- une catégorie suggérée parmi les catégories existantes ;
- des mots-clés ;
- des suggestions.

Règles métier :

- l’IA ne crée pas de catégorie ;
- l’IA n’écrase pas une catégorie existante ;
- l’IA ne change pas automatiquement le statut, la priorité ou l’assignation ;
- l’IA ne ferme jamais un ticket ;
- si OpenRouter est indisponible pendant l’ingestion e-mail, le ticket reste créé et
  l’API indique `aiAnalysis: unavailable`.

## Sécurité

- séparation des rôles `ROLE_CLIENT`, `ROLE_TECHNICIAN` et `ROLE_ADMIN` ;
- ownership strict sur les tickets Client ;
- actions web protégées par CSRF ;
- API e-mail protégée par Bearer ;
- secrets hors Git ;
- `.env.local` ignoré ;
- credentials n8n non exportés ;
- aucun mot de passe ou token réel dans la documentation.

## Tests et validations

Les tests du dépôt sont des scripts PHP autonomes :

```bash
php tests/Controller/SecurityAccessMatrixTest.php
php tests/Controller/ClientE2EFlowTest.php
php tests/Controller/TechnicianE2EFlowTest.php
php tests/Controller/AdminE2EFlowTest.php
php tests/Controller/EmailIngestionE2EFlowTest.php
php tests/AI/AIFinalValidationTest.php
php tests/Doctrine/DoctrineIntegrityTest.php
php tests/Controller/UiSmokeTest.php
```

Validations générales :

```bash
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:schema:validate
composer validate --no-check-publish
git diff --check
```

## Scénario de démonstration

Durée cible : 5 à 10 minutes.

1. Se connecter comme Client.
2. Créer un ticket depuis l’application.
3. Montrer l’analyse/catégorisation IA si elle a été déclenchée ou préparée dans les
   données de démonstration.
4. Se connecter comme Technicien.
5. Prendre en charge le ticket et ajouter une intervention.
6. Revenir côté Client et montrer l’intervention visible.
7. Ouvrir l’assistant flottant.
8. Poser une question couverte par la base de connaissances, par exemple Wi-Fi ou
   imprimante.
9. Montrer l’escalade “Créer une demande” si `needsTechnician` vaut `true`.
10. Ouvrir le formulaire prérempli, modifier si nécessaire, puis valider.
11. Envoyer un e-mail réel via la boîte surveillée par n8n.
12. Montrer le ticket `EMAIL` créé avec `requesterEmail`.
13. Rejouer le même `messageId` et montrer `duplicate: true` sans second ticket.
14. Si le temps le permet, présenter l’espace Admin en lecture seule.

## Limites du MVP

1. Les pièces jointes e-mail ne sont pas traitées.
2. Le workflow ne met pas en place de retry applicatif personnalisé.
3. L’ingestion IMAP/API dépend de credentials locaux.
4. La disponibilité et les quotas OpenRouter ne sont pas garantis par l’application.
5. L’IA est assistive et ne prend pas de décision métier autonome.
6. L’Admin ne fournit pas de CRUD complet.
7. Aucun compte de démonstration permanent ou credential réel n’est versionné.
8. Le MVP ne comprend pas de RAG, embeddings ou base vectorielle.

## Pistes V2 — non implémentées

- traitement des pièces jointes e-mail ;
- retry, monitoring et observabilité avancée du flux e-mail ;
- administration CRUD complète ;
- providers IA alternatifs ;
- RAG ou recherche sémantique avancée ;
- intégrations GLPI, Jira ou GitHub Issues ;
- environnement de déploiement de production.

## Roadmap

Consulter [ROADMAP/README.md](./ROADMAP/README.md) et les fichiers de phase pour
l’état versionné des tâches.
