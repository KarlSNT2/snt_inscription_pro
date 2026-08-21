<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.3.x -> 1.4.0.
 *  - Table de cache serveur des établissements INSEE
 *    `ps_snt_inscription_pro_insee_cache` (clé = SIRET). Objectif : éviter le
 *    second appel INSEE au submit (le premier, au blur, l'a déjà récupéré) et
 *    dédoublonner les SIRET déjà vus → économie de quota INSEE.
 *  - Clé de configuration `SNT_IP_INSEE_CACHE_TTL` (durée de vie du cache, en s).
 *
 * Idempotent : ré-exécutable sans erreur.
 */
function upgrade_module_1_4_0($module): bool
{
    unset($module);
    $db = Db::getInstance();

    // 1) Table de cache INSEE.
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_insee_cache` (
        `siret`    VARCHAR(14)  NOT NULL,
        `company`  VARCHAR(255) DEFAULT NULL,
        `active`   TINYINT(1)   NOT NULL DEFAULT 0,
        `closed`   TINYINT(1)   NOT NULL DEFAULT 0,
        `address1` VARCHAR(255) DEFAULT NULL,
        `address2` VARCHAR(255) DEFAULT NULL,
        `postcode` VARCHAR(16)  DEFAULT NULL,
        `city`     VARCHAR(255) DEFAULT NULL,
        `date_add` DATETIME     NOT NULL,
        PRIMARY KEY (`siret`),
        KEY `idx_date_add` (`date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    if (!$db->execute($sql)) {
        return false;
    }

    // 2) Nouvelle clé de configuration (uniquement si absente).
    if (!Configuration::hasKey('SNT_IP_INSEE_CACHE_TTL')) {
        Configuration::updateValue('SNT_IP_INSEE_CACHE_TTL', '86400');
    }

    return true;
}
