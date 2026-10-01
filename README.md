# Pilot AI

## Configuration locale

Les valeurs propres à une machine doivent être définies dans `.env.local` ou dans
l'environnement local, jamais dans un commit. `.env.local` est ignoré par Git.

- `APP_SECRET` : secret aléatoire privé utilisé par Symfony.
- `DATABASE_URL` : URL Doctrine PostgreSQL, par exemple au format
  `postgresql://<user>:<password>@127.0.0.1:5432/<database>?serverVersion=17&charset=utf8`.
  Les valeurs illustratives de `.env` ne sont pas des identifiants utilisables.
- `PILOTAI_EMAIL_WEBHOOK_SECRET` : secret partagé privé pour le webhook e-mail.
- `OPENROUTER_API_KEY` : clé API privée si le provider OpenRouter est utilisé.
- `PILOTAI_EMAIL_SYSTEM_USER_EMAIL` : adresse locale du compte système e-mail.
- `POSTGRES_PASSWORD` : mot de passe local du service PostgreSQL Docker. Le fallback
  Compose est réservé au développement local. Pour utiliser la valeur de `.env.local`
  lors du démarrage Docker, lancer `docker compose --env-file .env.local up -d` et
  configurer `DATABASE_URL` avec les mêmes identifiants.

Ne jamais commiter de secrets ni de credentials réels.
