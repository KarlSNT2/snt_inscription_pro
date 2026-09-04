START TRANSACTION;

-- 1) Créer le groupe temporaire « Pro à compléter »
INSERT INTO `ps_group` (`reduction`, `price_display_method`, `show_prices`, `date_add`, `date_upd`)
VALUES (0, 0, 1, NOW(), NOW());
SET @invite_group := LAST_INSERT_ID();

-- 2) Nom du groupe pour chaque langue active
INSERT INTO `ps_group_lang` (`id_group`, `id_lang`, `name`)
SELECT @invite_group, l.`id_lang`, 'Pro à compléter'
FROM `ps_lang` l
WHERE l.`active` = 1;

-- 3) Rattacher le groupe à chaque boutique
INSERT INTO `ps_group_shop` (`id_group`, `id_shop`)
SELECT @invite_group, s.`id_shop` FROM `ps_shop` s;

-- 4) Ajouter au groupe TOUS les clients ayant une raison sociale (AVANT le reset)
INSERT IGNORE INTO `ps_customer_group` (`id_customer`, `id_group`)
SELECT c.`id_customer`, @invite_group
FROM `ps_customer` c
WHERE c.`company` IS NOT NULL AND TRIM(c.`company`) <> '';

-- 5) Enregistrer l'id du groupe dans la config du module (déclencheur du bandeau)
DELETE FROM `ps_configuration` WHERE `name` = 'SNT_IP_INVITE_GROUP_ID';
INSERT INTO `ps_configuration` (`name`, `value`, `date_add`, `date_upd`)
VALUES ('SNT_IP_INVITE_GROUP_ID', @invite_group, NOW(), NOW());

-- 6) Réinitialiser la raison sociale pour ces clients
UPDATE `ps_customer`
SET `company` = ''
WHERE `company` IS NOT NULL AND TRIM(`company`) <> '';

-- Vérifier avant de valider :
SELECT @invite_group AS id_groupe_cree,
       (SELECT COUNT(*) FROM `ps_customer_group` WHERE `id_group` = @invite_group) AS clients_dans_groupe;

COMMIT;