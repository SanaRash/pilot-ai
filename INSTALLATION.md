# Installation Pilot AI

Ce guide installe l’application localement avec PostgreSQL dans Docker. Il ne
configure pas automatiquement un compte IMAP, n8n ou un fournisseur IA.

## Prérequis

- PHP >= 8.4 avec les extensions requises par Composer et PDO PostgreSQL ;
- Composer ;
- Symfony CLI, utilisé pour lancer le serveur web local ;
- Docker et Docker Compose ;
- Git.

Le fichier [compose.yaml](./compose.yaml) utilise PostgreSQL 17.

## Cloner le projet

```bash
git clone <repository-url>
cd pilot-ai
```

Remplacer `<repository-url>` par l’URL du dépôt auquel vous avez accès.

## Installer les dépendances

```bash
composer install
```

## Configuration locale

Créer `.env.local` à la racine du projet. Ce fichier est ignoré par Git :

```bash
touch .env.local
```

Renseigner localement les variables nécessaires, sans commiter ce fichier :

```dotenv
APP_SECRET=<secret-local-aleatoire>
POSTGRES_PASSWORD=<mot-de-passe-local-url-encode>
DATABASE_URL="postgresql://pilot_ai:<mot-de-passe-url-encode>@127.0.0.1:5432/pilot_ai?serverVersion=17&charset=utf8"
OPENROUTER_API_KEY=
PILOTAI_EMAIL_WEBHOOK_SECRET=
PILOTAI_EMAIL_SYSTEM_USER_EMAIL=email-ingestion@pilot-ai.internal
```

Générer une valeur privée pour `APP_SECRET` localement, par exemple avec
`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, puis la placer dans `.env.local`.
Si un mot de passe contient des caractères réservés dans une URL, l’encoder pour
`DATABASE_URL`. `POSTGRES_PASSWORD` et les identifiants de `DATABASE_URL` doivent
correspondre.

`OPENROUTER_API_KEY` est nécessaire aux appels réels vers OpenRouter. Le secret
`PILOTAI_EMAIL_WEBHOOK_SECRET` est requis pour activer l’authentification de l’API
d’ingestion. `PILOTAI_EMAIL_SYSTEM_USER_EMAIL` désigne le compte système utilisé
comme auteur des tickets e-mail ; l’adresse par défaut illustrative peut être
remplacée localement. Aucune de ces valeurs ne doit être publiée dans Git.

## Démarrer PostgreSQL

Le Compose lit `.env.local` explicitement afin d’utiliser `POSTGRES_PASSWORD` :

```bash
docker compose --env-file .env.local up -d
docker compose ps
```

Confirmer que le conteneur `database` est démarré. Le `DATABASE_URL` de Symfony doit
pointer vers le même hôte/port, base, utilisateur et mot de passe que le service
PostgreSQL. Le service local publie le port `5432`.

## Créer le schéma

Appliquer les migrations versionnées :

```bash
php bin/console doctrine:migrations:migrate
```

Vérifier le mapping et le schéma :

```bash
php bin/console doctrine:schema:validate
```

Les migrations créent les tables, mais ne préremplissent pas de comptes de
démonstration ni de catégories.

## Comptes utilisateurs

Créer interactivement les comptes nécessaires à la démonstration :

```bash
php bin/console app:create-user
```

Choisir le rôle au prompt. Créer localement un compte `ROLE_CLIENT`, un
`ROLE_TECHNICIAN` et un `ROLE_ADMIN`, et conserver leurs identifiants en lieu sûr.
Ne pas inscrire d’adresse personnelle, mot de passe ou credential de démonstration
dans le dépôt.

L’inscription publique crée uniquement un compte Client. Il n’existe pas de commande
de création de catégorie déclarée dans le dépôt ; si la démonstration nécessite une
modification de catégorie, préparer une catégorie dans l’environnement de
démonstration par le mécanisme de gestion local convenu. Ce mécanisme est à confirmer.

## Compte système d’ingestion e-mail

Uniquement si l’API d’ingestion doit être utilisée, après avoir configuré
`PILOTAI_EMAIL_SYSTEM_USER_EMAIL` :

```bash
php bin/console app:provision-email-system-user
```

Cette commande crée ou vérifie le compte système utilisé comme `createdBy` pour les
tickets reçus par l’API. Ce compte n’est pas un compte de connexion de démonstration.

## Lancer Symfony

```bash
symfony server:start
```

Ouvrir [http://127.0.0.1:8000](http://127.0.0.1:8000).

## Tests et validations disponibles

Les tests concernés sont des scripts PHP autonomes, non des commandes PHPUnit :

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

Validations utiles :

```bash
composer validate --no-check-publish
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:schema:validate
```

## Après installation

Lire le [README du projet](./README.md) pour les rôles, l’architecture, les
fonctionnalités, le scénario de démonstration et les limites MVP. Le workflow de
réception IMAP est décrit séparément dans [n8n/README.md](./n8n/README.md).

Ne jamais commiter `.env.local`, mot de passe PostgreSQL, clé OpenRouter, secret
Bearer, credential IMAP ou autre secret réel.
