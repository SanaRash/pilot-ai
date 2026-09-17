# Security Rules

- `authenticated != authorized`.
- CLIENT ne voit que ses propres données.
- TECHNICIAN n'accède pas à l'administration.
- ADMIN ne doit pas être attribuable via formulaire public.
- CSRF actif sur les formulaires web.
- Mot de passe toujours hashé.
- Pas de secret dans Git.
- Les endpoints n8n/API futurs nécessitent une authentification adaptée.
- Toute action destructrice demande contrôle d'autorisation et confirmation adaptée.
