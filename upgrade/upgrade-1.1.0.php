<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.0.x -> 1.1.0.
 *  - Table de journalisation interne `ps_snt_inscription_pro_log`.
 *  - Colonne `needs_review` sur la table module (comptes acceptés sans
 *    validation INSEE).
 *  - Nouvelles clés de configuration (support, rate-limit, rétention, alertes).
 *
 * Idempotent : ré-exécutable sans erreur.
 */
function upgrade_module_1_1_0($module): bool
{
    unset($module);
    $db = Db::getInstance();

    // 1) Table de logs.
    $logTable = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_log` (
        `id_log`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `type`        VARCHAR(32)  NOT NULL,
        `severity`    TINYINT(1)   NOT NULL DEFAULT 1,
        `id_customer` INT(11) UNSIGNED DEFAULT NULL,
        `siret`       VARCHAR(14)  DEFAULT NULL,
        `ip`          VARCHAR(45)  DEFAULT NULL,
        `message`     VARCHAR(255) DEFAULT NULL,
        `context`     TEXT         DEFAULT NULL,
        `date_add`    DATETIME     NOT NULL,
        PRIMARY KEY (`id_log`),
        KEY `idx_type_date` (`type`, `date_add`),
        KEY `idx_ip_date` (`ip`, `date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    if (!$db->execute($logTable)) {
        return false;
    }

    // 2) Colonne `needs_review` (ajoutée seulement si absente).
    $cols = $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'snt_inscription_pro` LIKE "needs_review"');
    if (!is_array($cols) || count($cols) === 0) {
        if (!$db->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'snt_inscription_pro`
             ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0'
        )) {
            return false;
        }
    }

    // 3) Nouvelles clés de configuration (uniquement si absentes).
    $defaults = [
        'SNT_IP_SUPPORT_EMAIL'      => '',
        'SNT_IP_RATELIMIT_MAX'      => '10',
        'SNT_IP_RATELIMIT_WINDOW'   => '60',
        'SNT_IP_LOG_RETENTION_DAYS' => '90',
        'SNT_IP_ALERT_THROTTLE'     => '3600',
    ];
    foreach ($defaults as $key => $value) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $value);
        }
    }

    return true;
}
