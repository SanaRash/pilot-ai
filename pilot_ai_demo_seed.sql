-- Pilot AI — données de démonstration
-- Ajoute des catégories, 6 tickets réalistes, historique, interventions et analyses IA.
-- Le script utilise le premier utilisateur actif ROLE_CLIENT et ROLE_TECHNICIAN existants.
-- Il est réexécutable : les tickets [DEMO] existants ne sont pas dupliqués.

DO $$
DECLARE
    v_client_id INT;
    v_tech_id INT;

    v_cat_reseau INT;
    v_cat_impression INT;
    v_cat_materiel INT;
    v_cat_logiciel INT;
    v_cat_telephonie INT;

    v_ticket_vpn INT;
    v_ticket_printer INT;
    v_ticket_app INT;
    v_ticket_phone INT;
    v_ticket_share INT;
    v_ticket_install INT;
BEGIN
    SELECT id INTO v_client_id
    FROM "user"
    WHERE is_active = TRUE
      AND roles::jsonb ? 'ROLE_CLIENT'
    ORDER BY id
    LIMIT 1;

    SELECT id INTO v_tech_id
    FROM "user"
    WHERE is_active = TRUE
      AND roles::jsonb ? 'ROLE_TECHNICIAN'
    ORDER BY id
    LIMIT 1;

    IF v_client_id IS NULL THEN
        RAISE EXCEPTION 'Aucun utilisateur actif ROLE_CLIENT trouvé. Crée d''abord un client avec app:create-user.';
    END IF;

    IF v_tech_id IS NULL THEN
        RAISE EXCEPTION 'Aucun utilisateur actif ROLE_TECHNICIAN trouvé. Crée d''abord un technicien avec app:create-user.';
    END IF;

    INSERT INTO category (name)
    SELECT 'Réseau'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Réseau');

    INSERT INTO category (name)
    SELECT 'Impression'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Impression');

    INSERT INTO category (name)
    SELECT 'Logiciel'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Logiciel');

    INSERT INTO category (name)
    SELECT 'Matériel'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Matériel');

    INSERT INTO category (name)
    SELECT 'Téléphonie'
    WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = 'Téléphonie');

    SELECT id INTO v_cat_reseau FROM category WHERE name = 'Réseau' ORDER BY id LIMIT 1;
    SELECT id INTO v_cat_impression FROM category WHERE name = 'Impression' ORDER BY id LIMIT 1;
    SELECT id INTO v_cat_materiel FROM category WHERE name = 'Matériel' ORDER BY id LIMIT 1;
    SELECT id INTO v_cat_logiciel FROM category WHERE name = 'Logiciel' ORDER BY id LIMIT 1;
    SELECT id INTO v_cat_telephonie FROM category WHERE name = 'Téléphonie' ORDER BY id LIMIT 1;

    -- 1. Ticket OPEN non assigné
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] VPN inaccessible depuis le domicile',
        'Depuis ce matin, la connexion VPN se coupe après la saisie des identifiants. L''accès Internet fonctionne normalement, mais les ressources internes restent inaccessibles.',
        'OPEN', 'HIGH', 'APP',
        NOW() - INTERVAL '2 hours',
        NULL,
        v_cat_reseau, v_client_id, NULL
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] VPN inaccessible depuis le domicile'
    );

    SELECT id INTO v_ticket_vpn
    FROM ticket
    WHERE title = '[DEMO] VPN inaccessible depuis le domicile'
    ORDER BY id DESC LIMIT 1;

    -- 2. Ticket IN_PROGRESS assigné
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] Imprimante couleur bloquée sur erreur toner',
        'L''imprimante du bureau affiche une erreur toner alors que la cartouche vient d''être remplacée. Les impressions restent bloquées dans la file.',
        'IN_PROGRESS', 'MEDIUM', 'APP',
        NOW() - INTERVAL '1 day',
        NOW() - INTERVAL '20 hours',
        v_cat_impression, v_client_id, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] Imprimante couleur bloquée sur erreur toner'
    );

    SELECT id INTO v_ticket_printer
    FROM ticket
    WHERE title = '[DEMO] Imprimante couleur bloquée sur erreur toner'
    ORDER BY id DESC LIMIT 1;

    -- 3. Ticket RESOLVED assigné
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] Application métier très lente après mise à jour',
        'Après la dernière mise à jour, l''écran de recherche met plus de 30 secondes à charger. Le problème touche plusieurs postes du service.',
        'RESOLVED', 'HIGH', 'APP',
        NOW() - INTERVAL '3 days',
        NOW() - INTERVAL '2 days',
        v_cat_logiciel, v_client_id, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] Application métier très lente après mise à jour'
    );

    SELECT id INTO v_ticket_app
    FROM ticket
    WHERE title = '[DEMO] Application métier très lente après mise à jour'
    ORDER BY id DESC LIMIT 1;

    -- 4. Ticket OPEN urgent non assigné
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] Téléphone IP sans tonalité à l''accueil',
        'Le poste téléphonique principal de l''accueil n''a plus de tonalité et ne peut ni émettre ni recevoir d''appels depuis environ 15 minutes.',
        'OPEN', 'URGENT', 'APP',
        NOW() - INTERVAL '35 minutes',
        NULL,
        v_cat_telephonie, v_client_id, NULL
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] Téléphone IP sans tonalité à l''accueil'
    );

    SELECT id INTO v_ticket_phone
    FROM ticket
    WHERE title = '[DEMO] Téléphone IP sans tonalité à l''accueil'
    ORDER BY id DESC LIMIT 1;

    -- 5. Ticket IN_PROGRESS assigné
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] Accès refusé au dossier partagé Comptabilité',
        'L''utilisateur peut ouvrir les autres dossiers réseau mais reçoit « Accès refusé » sur le partage Comptabilité depuis son changement de poste.',
        'IN_PROGRESS', 'MEDIUM', 'APP',
        NOW() - INTERVAL '8 hours',
        NOW() - INTERVAL '6 hours',
        v_cat_reseau, v_client_id, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] Accès refusé au dossier partagé Comptabilité'
    );

    SELECT id INTO v_ticket_share
    FROM ticket
    WHERE title = '[DEMO] Accès refusé au dossier partagé Comptabilité'
    ORDER BY id DESC LIMIT 1;

    -- 6. Ticket CLOSED
    INSERT INTO ticket
        (title, description, status, priority, source, created_at, updated_at, category_id, created_by_id, assigned_to_id)
    SELECT
        '[DEMO] Installation de 7-Zip sur un poste utilisateur',
        'Demande d''installation de 7-Zip pour permettre l''ouverture d''archives reçues de partenaires externes.',
        'CLOSED', 'LOW', 'APP',
        NOW() - INTERVAL '5 days',
        NOW() - INTERVAL '4 days',
        v_cat_logiciel, v_client_id, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket WHERE title = '[DEMO] Installation de 7-Zip sur un poste utilisateur'
    );

    SELECT id INTO v_ticket_install
    FROM ticket
    WHERE title = '[DEMO] Installation de 7-Zip sur un poste utilisateur'
    ORDER BY id DESC LIMIT 1;

    -- Historique minimal de création pour tous les tickets
    INSERT INTO ticket_history (action, old_value, new_value, created_at, ticket_id, changed_by_id)
    SELECT 'TICKET_CREATED', NULL, NULL, t.created_at, t.id, v_client_id
    FROM ticket t
    WHERE t.id IN (v_ticket_vpn, v_ticket_printer, v_ticket_app, v_ticket_phone, v_ticket_share, v_ticket_install)
      AND NOT EXISTS (
          SELECT 1 FROM ticket_history h
          WHERE h.ticket_id = t.id AND h.action = 'TICKET_CREATED'
      );

    -- Historiques métier
    INSERT INTO ticket_history (action, old_value, new_value, created_at, ticket_id, changed_by_id)
    SELECT 'TICKET_ASSIGNED', NULL, v_tech_id::text, NOW() - INTERVAL '22 hours', v_ticket_printer, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket_history WHERE ticket_id = v_ticket_printer AND action = 'TICKET_ASSIGNED'
    );

    INSERT INTO ticket_history (action, old_value, new_value, created_at, ticket_id, changed_by_id)
    SELECT 'STATUS_CHANGED', 'OPEN', 'IN_PROGRESS', NOW() - INTERVAL '20 hours', v_ticket_printer, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket_history WHERE ticket_id = v_ticket_printer AND action = 'STATUS_CHANGED'
    );

    INSERT INTO ticket_history (action, old_value, new_value, created_at, ticket_id, changed_by_id)
    SELECT 'STATUS_CHANGED', 'IN_PROGRESS', 'RESOLVED', NOW() - INTERVAL '2 days', v_ticket_app, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket_history WHERE ticket_id = v_ticket_app AND action = 'STATUS_CHANGED'
    );

    INSERT INTO ticket_history (action, old_value, new_value, created_at, ticket_id, changed_by_id)
    SELECT 'STATUS_CHANGED', 'RESOLVED', 'CLOSED', NOW() - INTERVAL '4 days', v_ticket_install, v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM ticket_history WHERE ticket_id = v_ticket_install AND action = 'STATUS_CHANGED'
    );

    -- Interventions
    INSERT INTO intervention (content, created_at, ticket_id, technician_id)
    SELECT
        'Vérification de la file d''impression et réinitialisation du consommable dans l''interface de l''imprimante. Un test d''impression couleur est en cours.',
        NOW() - INTERVAL '19 hours',
        v_ticket_printer,
        v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM intervention
        WHERE ticket_id = v_ticket_printer
          AND content LIKE 'Vérification de la file d''impression%'
    );

    INSERT INTO intervention (content, created_at, ticket_id, technician_id)
    SELECT
        'Contrôle des logs applicatifs : un index de base de données était manquant après la mise à jour. L''index a été recréé et les temps de réponse sont revenus à la normale.',
        NOW() - INTERVAL '2 days',
        v_ticket_app,
        v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM intervention
        WHERE ticket_id = v_ticket_app
          AND content LIKE 'Contrôle des logs applicatifs%'
    );

    INSERT INTO intervention (content, created_at, ticket_id, technician_id)
    SELECT
        'Vérification des droits du groupe Active Directory et resynchronisation de l''appartenance de l''utilisateur. Test d''accès demandé.',
        NOW() - INTERVAL '5 hours',
        v_ticket_share,
        v_tech_id
    WHERE NOT EXISTS (
        SELECT 1 FROM intervention
        WHERE ticket_id = v_ticket_share
          AND content LIKE 'Vérification des droits du groupe Active Directory%'
    );

    -- Analyses IA de démonstration
    INSERT INTO aianalysis
        (summary, suggested_priority, suggested_category, keywords, suggestions, created_at, ticket_id)
    SELECT
        'Le client ne parvient plus à établir la connexion VPN alors que son accès Internet fonctionne.',
        'HIGH',
        'Réseau',
        '["VPN","connexion","accès distant","authentification"]'::json,
        '["Vérifier l''état du service VPN","Contrôler les logs d''authentification","Tester le compte utilisateur depuis un second poste"]'::json,
        NOW() - INTERVAL '90 minutes',
        v_ticket_vpn
    WHERE NOT EXISTS (
        SELECT 1 FROM aianalysis WHERE ticket_id = v_ticket_vpn
    );

    INSERT INTO aianalysis
        (summary, suggested_priority, suggested_category, keywords, suggestions, created_at, ticket_id)
    SELECT
        'Le téléphone principal de l''accueil est totalement indisponible pour les appels entrants et sortants.',
        'URGENT',
        'Téléphonie',
        '["VoIP","téléphone IP","tonalité","accueil"]'::json,
        '["Vérifier l''alimentation PoE","Contrôler l''enregistrement SIP","Tester le port réseau du téléphone"]'::json,
        NOW() - INTERVAL '20 minutes',
        v_ticket_phone
    WHERE NOT EXISTS (
        SELECT 1 FROM aianalysis WHERE ticket_id = v_ticket_phone
    );

    RAISE NOTICE 'Données de démonstration Pilot AI ajoutées avec succès.';
    RAISE NOTICE 'Client utilisé: %, technicien utilisé: %', v_client_id, v_tech_id;
END $$;
