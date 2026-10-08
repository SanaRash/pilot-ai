# Workflows n8n

## Réception e-mail IMAP

Le fichier `workflows/email-reception-imap.json` est prévu pour n8n 2.39.8. Il contient :

1. le nœud natif `Email Trigger (IMAP)` en version 2.2 ;
2. un nœud natif `Edit Fields` (`Set`) qui extrait l'adresse du champ `from` vers `sender` ;
3. un nœud natif `Edit Fields` (`Set`) qui normalise le champ `subject` ;
4. un nœud natif `Edit Fields` (`Set`) qui normalise le champ `textPlain` vers `content` ;
5. un nœud natif `Edit Fields` (`Set`) qui extrait le Message-ID IMAP vers `messageId` ;
6. un nœud natif `HTTP Request` qui transmet le JSON à Pilot AI.

Le trigger maintient une connexion IMAP persistante. Il ne repose pas sur un polling périodique. n8n surveille la connexion et la rétablit nativement en cas de coupure. Une reconnexion préventive est configurée toutes les 60 minutes afin de supporter les serveurs limitant la durée d'une connexion.

Le workflow transmet l'expéditeur, le sujet, le contenu texte et le Message-ID à l'API
Pilot AI. L'IA et toute la logique métier restent côté backend.

## Contrat et idempotence

- L'API est `POST /api/tickets/email`.
- Le body contient exactement `sender`, `subject`, `content` et `messageId`.
- `messageId` est obligatoire. Il est persisté sur le ticket et possède une contrainte unique en base.
- Une première requête crée un ticket et répond `201` avec `duplicate: false`.
- Le même Message-ID renvoie `200` avec le ticket existant et `duplicate: true`. Aucun nouvel
  historique, appel IA ou traitement de catégorie n'est effectué.
- Les champs absents ou invalides (notamment `messageId` ou `textPlain`) produisent une erreur
  de validation `422`; le nœud HTTP signale cette réponse non 2xx comme une exécution en erreur.
- L'IA est exécutée une seule fois après la création. Si elle est indisponible, le ticket reste
  créé et la réponse est `201` avec `aiAnalysis: unavailable`.

## Extraction du Message-ID

Dans le `format: simple` du nœud IMAP, les en-têtes `cc`, `date`, `from`, `subject` et `to`
sont exposés à la racine; les autres en-têtes sont placés dans `metadata`. Les noms d'en-têtes
sont normalisés en minuscules. Le header `Message-ID` est donc lu depuis
`$json.metadata['message-id']`, puis normalisé dans `messageId` (espaces périphériques retirés).
L'identifiant n'est pas reconstruit à partir de l'UID IMAP : c'est la valeur du véritable header
Message-ID qui est utilisée pour l'idempotence.

Si ce header est absent ou vide, `messageId` vaut `null`; l'API rejette alors le message avec
`422` au lieu de créer un ticket non dédupliquable.

### Extraction de l'expéditeur

Le format `simple` du trigger expose l'en-tête `From` dans `$json.from`. Le workflow utilise
`$json.from` en priorité et `$json.metadata.from` uniquement comme repli défensif. Il extrait
la première adresse e-mail syntaxiquement valide dans l'ordre d'apparition, y compris dans une
forme `Nom <email@example.com>`. Le nom d'affichage est supprimé, les espaces périphériques
sont retirés et la casse du local-part est conservée. Une valeur absente ou invalide produit
`sender: null`. Aucun autre en-tête (`reply-to`, `return-path` ou `to`) n'est utilisé.

Le contenu du message n'est pas journalisé explicitement par le workflow. Aucune adresse
complète n'est journalisée explicitement non plus.

### Extraction du sujet

Le format `simple` du trigger expose l'en-tête `Subject` dans `$json.subject`. Le workflow
utilise uniquement ce champ et le normalise dans `subject` en supprimant les espaces
périphériques. Les espaces internes, la casse, la ponctuation, les accents et les caractères
Unicode sont conservés. Une valeur absente, non textuelle, vide ou composée uniquement
d'espaces produit `subject: null`.

Aucun décodage RFC 2047 personnalisé n'est effectué. Une valeur MIME encore encodée est
conservée telle quelle après suppression des espaces périphériques ; une valeur déjà décodée
par n8n est conservée après cette même normalisation. Le corps n'est pas concerné par cette
normalisation du sujet.

### Extraction du contenu

Le format `simple` du trigger expose le corps texte dans `$json.textPlain` et le corps HTML
dans `$json.textHtml`. Cette tâche utilise uniquement `$json.textPlain` pour produire
`content`. Les espaces périphériques sont supprimés, tandis que les espaces internes, les
sauts de ligne, les paragraphes, la casse, la ponctuation et les caractères Unicode sont
conservés.

Si `textPlain` est absent, non textuel, vide ou composé uniquement d'espaces, `content` vaut
`null`, même si `textHtml` contient une valeur. L'API rejette ce payload avec `422`; le workflow
ne remplace pas silencieusement le contenu par une chaîne vide. Aucune conversion HTML vers
texte n'est effectuée et le HTML brut n'est pas injecté dans `content`. Les pièces jointes ne
sont ni téléchargées ni traitées. Après normalisation, les champs bruts `textPlain` et `textHtml`
ne sont pas propagés au nœud HTTP.

### Import et configuration

1. Dans n8n 2.39.8, importer `workflows/email-reception-imap.json`.
2. Créer manuellement un credential de type `IMAP` dans le gestionnaire de credentials n8n.
3. Renseigner dans ce credential l'hôte, le port, l'utilisateur, le mot de passe ou mot de passe d'application, ainsi que TLS selon les exigences du serveur.
4. Conserver la vérification du certificat TLS active. Une exception pour un certificat auto-signé doit rester limitée à un environnement local maîtrisé.
5. Associer ce credential au nœud `Réception e-mail IMAP`.
6. Créer un credential **Header Auth** nommé par exemple `Pilot AI Email API`, avec le nom d'en-tête `Authorization` et la valeur `Bearer <secret>`. Remplacer le marqueur par le vrai secret dans le gestionnaire de credentials n8n, jamais dans le workflow ou ce README.
7. Associer le credential au nœud `Créer ticket Pilot AI` et vérifier `Content-Type: application/json`.
8. Tester la connexion IMAP et l'appel HTTP depuis n8n, puis activer le workflow.

L'export ne contient volontairement ni bloc `credentials`, ni identifiant de credential, ni login,
ni mot de passe ni token. Après import, associer les deux credentials aux nœuds correspondants.
Le secret API côté backend est `PILOTAI_EMAIL_WEBHOOK_SECRET`, configuré dans `.env.local` ou
l'environnement du serveur. Ne jamais commiter sa valeur ni l'ajouter au workflow exporté.

### Accès local Docker → Symfony

Le workflow utilise `http://host.docker.internal:8000/api/tickets/email`. Avec Docker Desktop
Windows, `host.docker.internal` désigne l'hôte accessible depuis le conteneur, mais Symfony
doit écouter sur une interface joignable par Docker. Depuis WSL, démarrer le serveur local
uniquement pour le développement avec :

```bash
symfony server:start --listen-ip=0.0.0.0 --port=8000 --no-tls
```

Puis vérifier depuis le conteneur n8n que `host.docker.internal:8000` est joignable. Si le
pare-feu Windows bloque le port, autoriser uniquement le flux local nécessaire depuis le réseau
Docker/WSL. Arrêter le serveur avec `symfony server:stop` après le test. Cette commande n'est pas
une configuration de production : ne pas exposer ce serveur de développement sur un réseau
public et utiliser un endpoint HTTPS protégé pour un déploiement.

### Comportement du trigger

- La boîte surveillée est `INBOX`.
- Les pièces jointes ne sont pas téléchargées.
- Après réception, le message n'est ni déplacé ni marqué comme lu par le workflow.
- Le suivi natif du dernier UID est activé afin qu'une même arrivée ne déclenche pas plusieurs exécutions pendant le fonctionnement normal.
- Le backend déduplique durablement les requêtes reçues avec le même `messageId`; le suivi UID IMAP ne remplace pas cette idempotence.
- Aucun retry applicatif personnalisé n'est ajouté. Une erreur IMAP, HTTP ou de validation doit rester visible dans les exécutions et journaux techniques n8n.

### Test manuel

1. Utiliser une boîte IMAP de test ne contenant aucune donnée réelle sensible.
2. Vérifier le credential avec le test de connexion n8n.
3. Démarrer une écoute de test ou publier le workflow.
4. Envoyer un nouvel e-mail unique vers la boîte surveillée.
5. Vérifier dans l'exécution que les nodes produisent `sender`, `subject`, `content` et `messageId`, puis que `Créer ticket Pilot AI` retourne `201` et `duplicate: false`.
6. Renvoyer le même Message-ID : vérifier `200`, `duplicate: true` et le même identifiant de ticket.
7. Tester séparément un e-mail sans Message-ID et un e-mail sans `textPlain` : l'API doit répondre `422`, sans création silencieuse de ticket.
8. Tester un token invalide et vérifier `401`; vérifier qu'aucune valeur de credential n'apparaît dans l'export du workflow.
9. Pour vérifier la reconnexion IMAP, interrompre temporairement l'accès au serveur IMAP de test : l'erreur doit rester visible sans révéler les valeurs du credential, puis la connexion doit être rétablie par le mécanisme natif après restauration du service.

Les exécutions n8n peuvent conserver le payload e-mail complet, y compris expéditeur, sujet,
contenu et Message-ID. Limiter l'accès à l'instance et configurer la rétention/suppression des
exécutions selon la politique de données de l'environnement. Ne pas exporter ou partager des
exécutions contenant des données personnelles.

Le workflow ne marque volontairement pas les messages comme lus et n'implémente aucune remise
à zéro des anciens messages. Une réexécution du même mail reste sans doublon grâce au Message-ID
unique côté Pilot AI.
