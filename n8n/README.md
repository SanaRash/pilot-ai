# Workflows n8n

## Réception e-mail IMAP

Le fichier `workflows/email-reception-imap.json` est prévu pour n8n 2.39.8. Il contient :

1. le nœud natif `Email Trigger (IMAP)` en version 2.2 ;
2. un nœud natif `Edit Fields` (`Set`) qui extrait l'adresse du champ `from` vers `sender` ;
3. un nœud natif `Edit Fields` (`Set`) qui normalise le champ `subject` ;
4. un nœud `No Operation` permettant de constater l'exécution.

Le trigger maintient une connexion IMAP persistante. Il ne repose pas sur un polling périodique. n8n surveille la connexion et la rétablit nativement en cas de coupure. Une reconnexion préventive est configurée toutes les 60 minutes afin de supporter les serveurs limitant la durée d'une connexion.

Le workflow n'appelle aucune API Pilot AI, ne traite que l'expéditeur et le sujet et ne produit aucun effet métier.

### Extraction de l'expéditeur

Le format `simple` du trigger expose l'en-tête `From` dans `$json.from`. Le workflow utilise
`$json.from` en priorité et `$json.metadata.from` uniquement comme repli défensif. Il extrait
la première adresse e-mail syntaxiquement valide dans l'ordre d'apparition, y compris dans une
forme `Nom <email@example.com>`. Le nom d'affichage est supprimé, les espaces périphériques
sont retirés et la casse du local-part est conservée. Une valeur absente ou invalide produit
`sender: null`. Aucun autre en-tête (`reply-to`, `return-path` ou `to`) n'est utilisé.

Le contenu du message n'est pas extrait dans cette tâche. Aucune adresse complète n'est
journalisée explicitement par le workflow.

### Extraction du sujet

Le format `simple` du trigger expose l'en-tête `Subject` dans `$json.subject`. Le workflow
utilise uniquement ce champ et le normalise dans `subject` en supprimant les espaces
périphériques. Les espaces internes, la casse, la ponctuation, les accents et les caractères
Unicode sont conservés. Une valeur absente, non textuelle, vide ou composée uniquement
d'espaces produit `subject: null`.

Aucun décodage RFC 2047 personnalisé n'est effectué. Une valeur MIME encore encodée est
conservée telle quelle après suppression des espaces périphériques ; une valeur déjà décodée
par n8n est conservée après cette même normalisation. Le contenu du message n'est pas extrait.

### Import et configuration

1. Dans n8n 2.39.8, importer `workflows/email-reception-imap.json`.
2. Créer manuellement un credential de type `IMAP` dans le gestionnaire de credentials n8n.
3. Renseigner dans ce credential l'hôte, le port, l'utilisateur, le mot de passe ou mot de passe d'application, ainsi que TLS selon les exigences du serveur.
4. Conserver la vérification du certificat TLS active. Une exception pour un certificat auto-signé doit rester limitée à un environnement local maîtrisé.
5. Associer ce credential au nœud `Réception e-mail IMAP`.
6. Tester la connexion depuis l'interface n8n, puis publier le workflow.

L'export ne contient volontairement ni bloc `credentials`, ni identifiant de credential, ni login, ni mot de passe. Les secrets restent exclusivement dans le gestionnaire de credentials de l'instance n8n.

### Comportement du trigger

- La boîte surveillée est `INBOX`.
- Les pièces jointes ne sont pas téléchargées.
- Après réception, le message n'est ni déplacé ni marqué comme lu par le workflow.
- Le suivi natif du dernier UID est activé afin qu'une même arrivée ne déclenche pas plusieurs exécutions pendant le fonctionnement normal.
- Aucune déduplication métier fondée sur `messageId` n'est encore réalisée. Elle appartient à une tâche ultérieure.
- Aucun retry applicatif personnalisé n'est ajouté. Une erreur ou une indisponibilité IMAP doit rester visible dans les exécutions et journaux techniques n8n.

### Test manuel

1. Utiliser une boîte IMAP de test ne contenant aucune donnée réelle sensible.
2. Vérifier le credential avec le test de connexion n8n.
3. Démarrer une écoute de test ou publier le workflow.
4. Envoyer un nouvel e-mail unique vers la boîte surveillée.
5. Vérifier qu'une seule exécution apparaît, que `Extraire expéditeur` produit `sender`, que `Extraire sujet` produit `subject` et qu'elle atteint `Contrôle réception uniquement`.
6. Vérifier qu'aucun nœud HTTP, aucune extraction du contenu, aucune création de ticket et aucun appel IA ne sont exécutés.
7. Pour vérifier la reconnexion, interrompre temporairement l'accès au serveur IMAP de test : l'erreur doit être visible sans révéler les valeurs du credential, puis la connexion doit être rétablie par le mécanisme natif après restauration du service.

Chaque nouveau test de réception doit utiliser un nouvel e-mail. Le workflow ne marque volontairement pas les messages comme lus et n'implémente aucune remise à zéro ou réexécution métier des anciens messages.
