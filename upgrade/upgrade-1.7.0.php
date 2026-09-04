<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.6.x -> 1.7.0.
 *  - Nouvelle colonne booléenne `is_administration` sur `ps_snt_inscription_pro` :
 *    déclaration « Je suis une administration » (case à cocher du formulaire).
 *    Les administrations sont hors périmètre de la facturation électronique :
 *    si cochée, l'adresse de facturation électronique n'est pas exigée. Persistée
 *    par client et exposée à l'ERP via l'endpoint API.
 *  - Nettoyage : si une build intermédiaire a introduit une colonne `naf`
 *    (jamais publiée), on la supprime sur les deux tables (module + cache INSEE).
 *
 * Idempotent : ré-exécutable sans erreur (on teste l'existence avant d'agir).
 */
function upgrade_module_1_7_0($module): bool
{
    unset($module);
    $db      = Db::getInstance();
    $table   = _DB_PREFIX_ . 'snt_inscription_pro';
    $cache   = _DB_PREFIX_ . 'snt_inscription_pro_insee_cache';

    $ok = true;

    // Nettoyage d'une éventuelle colonne `naf` (build intermédiaire non publiée).
    $ok = dropColumnIfExists($db, $table, 'naf') && $ok;
    $ok = dropColumnIfExists($db, $cache, 'naf') && $ok;

    // Ajout de la colonne `is_administration` si elle n'existe pas déjà.
    if (!columnExists($db, $table, 'is_administration')) {
        $ok = (bool) $db->execute(
            'ALTER TABLE `' . $table . '`
                ADD COLUMN `is_administration` TINYINT(1) NOT NULL DEFAULT 0 AFTER `einvoicing_address`'
        ) && $ok;
    }

    return $ok;
}

function columnExists(Db $db, string $table, string $column): bool
{
    $rows = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . pSQL($column) . '\'');

    return is_array($rows) && count($rows) > 0;
}

function dropColumnIfExists(Db $db, string $table, string $column): bool
{
    if (!columnExists($db, $table, $column)) {
        return true;
    }

    return (bool) $db->execute('ALTER TABLE `' . $table . '` DROP COLUMN `' . bqSQL($column) . '`');
}
