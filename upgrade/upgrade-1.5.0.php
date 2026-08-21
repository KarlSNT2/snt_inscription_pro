<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.4.x -> 1.5.0.
 *  - Table de cache serveur des vérifications VIES
 *    `ps_snt_inscription_pro_vies_cache` (clé = n° TVA complet). Évite de
 *    re-solliciter VIES (lent + rate-limité) pour un numéro déjà vérifié.
 *  - Clés de configuration VIES (activation, mode strict, timeout, TTL cache).
 *
 * Ne touche PAS au formulaire ni au parcours : cette version pose uniquement la
 * fondation (moteur + stockage). Le sélecteur pays et le branchement de la
 * vérification arrivent dans une version ultérieure.
 *
 * Idempotent : ré-exécutable sans erreur.
 */
function upgrade_module_1_5_0($module): bool
{
    unset($module);
    $db = Db::getInstance();

    // 1) Table de cache VIES.
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_vies_cache` (
        `vat_number` VARCHAR(20)  NOT NULL,
        `valid`      TINYINT(1)   NOT NULL DEFAULT 0,
        `name`       VARCHAR(255) DEFAULT NULL,
        `address`    VARCHAR(512) DEFAULT NULL,
        `date_add`   DATETIME     NOT NULL,
        PRIMARY KEY (`vat_number`),
        KEY `idx_date_add` (`date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    if (!$db->execute($sql)) {
        return false;
    }

    // 2) Nouvelles clés de configuration (uniquement si absentes).
    $defaults = [
        'SNT_IP_VIES_ENABLE'    => '1',
        'SNT_IP_VIES_STRICT'    => '0',
        'SNT_IP_VIES_TIMEOUT'   => '5',
        'SNT_IP_VIES_CACHE_TTL' => '604800',
    ];
    foreach ($defaults as $key => $value) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $value);
        }
    }

    return true;
}
