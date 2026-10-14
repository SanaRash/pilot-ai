-- Pilot AI — base de connaissances de démonstration pour l'assistant client.
-- À exécuter après les migrations Doctrine.
-- Ce fichier est idempotent et ne contient aucun secret ni procédure interne sensible.

BEGIN;

INSERT INTO category (name)
SELECT category_name
FROM (
    VALUES
        ('Impression'),
        ('Logiciel'),
        ('Matériel'),
        ('Réseau'),
        ('Téléphonie')
) AS expected_categories(category_name)
WHERE NOT EXISTS (
    SELECT 1
    FROM category
    WHERE category.name = expected_categories.category_name
);

WITH demo_articles(title, category_name, content, keywords) AS (
    VALUES
        (
            'Wi-Fi connecté mais pas d''accès Internet',
            'Réseau',
            'Vérifiez si d''autres appareils ont accès à Internet. Désactivez puis réactivez le Wi-Fi sur votre ordinateur. Oubliez le réseau Wi-Fi puis reconnectez-vous. Redémarrez votre box ou votre routeur si vous êtes autorisé à le faire. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['wifi', 'internet', 'connexion', 'réseau', 'dns']
        ),
        (
            'Impossible de se connecter au Wi-Fi',
            'Réseau',
            'Vérifiez que vous sélectionnez le bon réseau Wi-Fi. Contrôlez le mot de passe utilisé sans le partager. Désactivez puis réactivez le Wi-Fi, puis redémarrez l''ordinateur. Si possible, testez un autre réseau. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['wifi', 'connexion', 'réseau', 'authentification']
        ),
        (
            'Connexion Internet très lente',
            'Réseau',
            'Vérifiez si la lenteur concerne uniquement votre appareil ou plusieurs appareils. Fermez les téléchargements et applications qui utilisent fortement Internet. Rapprochez-vous du point d''accès Wi-Fi ou testez un câble réseau si disponible. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['internet', 'lenteur', 'débit', 'réseau', 'wifi']
        ),
        (
            'VPN inaccessible',
            'Réseau',
            'Vérifiez d''abord que votre connexion Internet fonctionne. Fermez puis relancez le client VPN. Contrôlez vos identifiants sans les communiquer. Redémarrez l''ordinateur si nécessaire et notez le message d''erreur éventuel. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['vpn', 'accès distant', 'connexion', 'télétravail']
        ),
        (
            'Impossible d''accéder à un site interne',
            'Réseau',
            'Vérifiez que votre connexion Internet fonctionne. Testez l''adresse du site interne dans un autre navigateur. Fermez puis relancez le navigateur. Vérifiez si le VPN est nécessaire pour accéder à ce site. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['intranet', 'site interne', 'dns', 'réseau', 'vpn']
        ),
        (
            'Écran noir au démarrage',
            'Matériel',
            'Vérifiez que l''écran est allumé et correctement alimenté. Contrôlez le câble vidéo entre l''écran et l''ordinateur, puis débranchez et rebranchez-le. Si possible, testez un autre câble, un autre port vidéo ou un autre écran. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['écran noir', 'affichage', 'moniteur', 'démarrage', 'câble vidéo', 'matériel']
        ),
        (
            'Écran externe non détecté',
            'Matériel',
            'Vérifiez le câble HDMI ou DisplayPort et reconnectez-le des deux côtés. Testez un autre port vidéo si disponible. Ouvrez les paramètres d''affichage et lancez la détection des écrans. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['écran externe', 'hdmi', 'displayport', 'affichage', 'moniteur']
        ),
        (
            'Clavier ou souris non détecté',
            'Matériel',
            'Débranchez puis reconnectez le clavier ou la souris. Testez un autre port USB. Pour un périphérique sans fil, vérifiez les piles ou la batterie et reconnectez le récepteur USB. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['clavier', 'souris', 'usb', 'périphérique', 'matériel']
        ),
        (
            'Ordinateur très lent',
            'Matériel',
            'Redémarrez l''ordinateur. Fermez les applications inutiles et vérifiez que plusieurs programmes lourds ne fonctionnent pas simultanément. Vérifiez également que l''espace disque n''est pas presque plein. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['ordinateur lent', 'performance', 'mémoire', 'disque', 'matériel']
        ),
        (
            'Ordinateur ne démarre pas',
            'Matériel',
            'Vérifiez que le chargeur ou le câble d''alimentation est correctement branché. Débranchez les périphériques externes non indispensables. Maintenez le bouton d''alimentation quelques secondes puis réessayez. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['démarrage', 'alimentation', 'ordinateur', 'pc', 'chargeur']
        ),
        (
            'Imprimante hors ligne',
            'Impression',
            'Vérifiez que l''imprimante est allumée. Contrôlez le câble USB ou la connexion réseau selon le modèle. Redémarrez l''imprimante, puis vérifiez qu''elle apparaît disponible sur votre ordinateur. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['imprimante', 'hors ligne', 'impression', 'réseau']
        ),
        (
            'Document bloqué dans la file d''impression',
            'Impression',
            'Vérifiez que l''imprimante est allumée et disponible. Annulez le document bloqué si l''option est proposée, puis relancez l''impression. Vérifiez aussi qu''aucune alerte papier ou toner n''apparaît sur l''imprimante. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['file impression', 'document bloqué', 'imprimante', 'papier', 'toner']
        ),
        (
            'Impression de mauvaise qualité',
            'Impression',
            'Vérifiez que le papier utilisé est adapté. Contrôlez le niveau d''encre ou de toner si l''information est disponible. Relancez l''impression sur un document simple pour comparer. Si le défaut reste visible, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['qualité impression', 'toner', 'encre', 'papier', 'imprimante']
        ),
        (
            'Impossible d''ouvrir une application',
            'Logiciel',
            'Fermez l''application puis relancez-la. Redémarrez l''ordinateur si elle ne s''ouvre toujours pas. Vérifiez si un message d''erreur apparaît et notez son contenu général. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['application', 'ouverture', 'logiciel', 'erreur']
        ),
        (
            'Application qui se ferme toute seule',
            'Logiciel',
            'Relancez l''application et vérifiez si la fermeture se produit toujours au même moment. Fermez les autres applications ouvertes, puis redémarrez l''ordinateur. Notez le nom de l''application et le message d''erreur éventuel. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['application', 'fermeture', 'crash', 'logiciel', 'erreur']
        ),
        (
            'Mot de passe oublié',
            'Logiciel',
            'Utilisez la procédure de réinitialisation proposée sur l''écran de connexion si elle est disponible. Ne communiquez jamais votre mot de passe. Si vous ne recevez pas le message de réinitialisation ou si l''accès reste impossible, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['mot de passe', 'connexion', 'compte', 'réinitialisation']
        ),
        (
            'Compte verrouillé après plusieurs tentatives',
            'Logiciel',
            'Patientez quelques minutes si un délai de sécurité est indiqué. Vérifiez que le bon compte est utilisé et que la touche Verr Maj n''est pas activée. Ne multipliez pas les essais si le compte reste bloqué. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['compte verrouillé', 'connexion', 'authentification', 'tentatives']
        ),
        (
            'Micro non détecté pendant un appel',
            'Téléphonie',
            'Vérifiez que le micro ou le casque est bien branché. Sélectionnez le bon périphérique audio dans l''application d''appel. Vérifiez que le micro n''est pas coupé dans l''application ou sur le casque. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['micro', 'appel', 'teams', 'audio', 'casque']
        ),
        (
            'Aucun son pendant un appel',
            'Téléphonie',
            'Vérifiez le volume de l''ordinateur et de l''application d''appel. Sélectionnez le bon haut-parleur ou casque dans les paramètres audio. Débranchez puis rebranchez le casque si nécessaire. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['son', 'appel', 'audio', 'haut-parleur', 'casque']
        ),
        (
            'Impossible de passer ou recevoir des appels',
            'Téléphonie',
            'Vérifiez que l''application de téléphonie est ouverte et connectée. Contrôlez que le réseau fonctionne et que votre casque ou téléphone est bien branché. Redémarrez l''application si nécessaire. Si le problème persiste, créez une demande afin qu''un technicien puisse prendre le relais.',
            ARRAY['appel', 'téléphonie', 'softphone', 'réception', 'émission']
        )
)
INSERT INTO knowledge_article (
    title,
    content,
    keywords,
    category_id,
    is_active,
    is_client_safe,
    created_at,
    updated_at
)
SELECT
    demo_articles.title,
    demo_articles.content,
    to_json(demo_articles.keywords),
    (
        SELECT category.id
        FROM category
        WHERE category.name = demo_articles.category_name
        ORDER BY category.id ASC
        LIMIT 1
    ),
    TRUE,
    TRUE,
    NOW(),
    NOW()
FROM demo_articles
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledge_article
    WHERE knowledge_article.title = demo_articles.title
);

COMMIT;
