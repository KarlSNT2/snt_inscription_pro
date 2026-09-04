<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.5.x -> 1.6.0.
 *  - Nouvelle colonne `einvoicing_address` dans `ps_snt_inscription_pro` :
 *    adresse de facturation électronique (réforme de la facturation
 *    électronique). SIRET dans la grande majorité des cas, ou un autre
 *    identifiant de routage. Collectée à l'inscription (obligatoire branche FR)
 *    et exposée à l'ERP via l'endpoint API.
 *
 * Idempotent : ré-exécutable sans erreur (on ne recrée pas une colonne existante).
 */
function upgrade_module_1_6_0($module): bool
{
    unset($module);
    $db    = Db::getInstance();
    $table = _DB_PREFIX_ . 'snt_inscription_pro';

    // Ajout de la colonne uniquement si elle n'existe pas déjà.
    $columns = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE \'einvoicing_address\'');
    if (is_array($columns) && count($columns) > 0) {
        return true;
    }

    $sql = 'ALTER TABLE `' . $table . '`
        ADD COLUMN `einvoicing_address` VARCHAR(255) DEFAULT NULL AFTER `accounting_email`';

    return (bool) $db->execute($sql);
}
