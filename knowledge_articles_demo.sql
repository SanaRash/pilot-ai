-- Pilot AI — articles de démonstration pour l’assistant client.
-- À exécuter après les migrations Doctrine.
-- Ce fichier ne contient aucun secret ni procédure interne sensible.

WITH reseau AS (
    INSERT INTO category (name)
    SELECT 'Réseau'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Réseau')
    RETURNING id
),
materiel AS (
    INSERT INTO category (name)
    SELECT 'Matériel'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Matériel')
    RETURNING id
),
impression AS (
    INSERT INTO category (name)
    SELECT 'Impression'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Impression')
    RETURNING id
),
logiciel AS (
    INSERT INTO category (name)
    SELECT 'Logiciel'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Logiciel')
    RETURNING id
)
INSERT INTO knowledge_article (
    category_id,
    title,
    content,
    keywords,
    is_active,
    is_client_safe,
    created_at,
    updated_at
)
VALUES
(
    (SELECT id FROM category WHERE name = 'Réseau' LIMIT 1),
    'Dépannage Wi-Fi / Internet',
    'Vérifiez d’abord si d’autres appareils ont accès à Internet. Si aucun appareil ne fonctionne, redémarrez votre box. Si le problème concerne uniquement votre ordinateur, désactivez puis réactivez le Wi-Fi, puis vérifiez que vous êtes connecté au bon réseau.',
    '["wifi","wi-fi","internet","connexion","réseau","dns","box"]'::json,
    true,
    true,
    NOW(),
    NOW()
),
(
    (SELECT id FROM category WHERE name = 'Matériel' LIMIT 1),
    'Écran noir',
    'Vérifiez que l’écran est bien alimenté et allumé. Contrôlez ensuite le câble vidéo entre l’écran et l’ordinateur. Si possible, testez un autre câble ou un autre écran pour identifier si le problème vient du moniteur ou de l’ordinateur.',
    '["écran","noir","affichage","moniteur"]'::json,
    true,
    true,
    NOW(),
    NOW()
),
(
    (SELECT id FROM category WHERE name = 'Impression' LIMIT 1),
    'Imprimante hors ligne',
    'Vérifiez que l’imprimante est allumée, connectée au réseau et qu’aucun message d’erreur n’apparaît sur son écran. Relancez ensuite l’impression. Si l’imprimante reste hors ligne, redémarrez-la et vérifiez que votre ordinateur utilise la bonne imprimante.',
    '["imprimante","impression","hors ligne"]'::json,
    true,
    true,
    NOW(),
    NOW()
),
(
    (SELECT id FROM category WHERE name = 'Logiciel' LIMIT 1),
    'Mot de passe oublié',
    'Utilisez la procédure de réinitialisation de mot de passe disponible sur l’écran de connexion si elle est proposée. Si vous ne recevez pas d’e-mail de réinitialisation ou si votre compte semble bloqué, contactez le support en précisant votre adresse professionnelle.',
    '["mot de passe","connexion","compte"]'::json,
    true,
    true,
    NOW(),
    NOW()
),
(
    (SELECT id FROM category WHERE name = 'Réseau' LIMIT 1),
    'VPN inaccessible',
    'Vérifiez d’abord que votre connexion Internet fonctionne sans le VPN. Relancez ensuite le client VPN et contrôlez que vos identifiants sont corrects. Si l’accès distant reste impossible, notez le message d’erreur affiché et transmettez-le au support.',
    '["vpn","accès distant","connexion"]'::json,
    true,
    true,
    NOW(),
    NOW()
);
