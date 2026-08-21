<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.2.x -> 1.3.0.
 *  - Colonne `accounting_email` sur la table module (email du service comptable,
 *    demandé côté compta, exposé à l'ERP via l'endpoint API).
 *  - Colonne `locked_address_id` : référence l'adresse « siège social » créée
 *    d'office et verrouillée (non éditable) pour le client pro.
 *  - Enregistrement des hooks de verrouillage d'adresse
 *    (`actionObjectAddressUpdateBefore` / `actionObjectAddressDeleteBefore`).
 *
 * Le téléphone pro n'est PAS persisté : il n'est utilisé qu'à la création de
 * l'adresse (une adresse PS doit porter un téléphone).
 *
 * Idempotent : ré-exécutable sans erreur.
 */
function upgrade_module_1_3_0($module): bool
{
    $db    = Db::getInstance();
    $table = _DB_PREFIX_ . 'snt_inscription_pro';

    // 1) Colonne accounting_email.
    $cols = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "accounting_email"');
    if (!is_array($cols) || count($cols) === 0) {
        if (!$db->execute(
            'ALTER TABLE `' . $table . '` ADD COLUMN `accounting_email` VARCHAR(255) DEFAULT NULL AFTER `afe`'
        )) {
            return false;
        }
    }

    // 2) Colonne locked_address_id.
    $cols = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "locked_address_id"');
    if (!is_array($cols) || count($cols) === 0) {
        if (!$db->execute(
            'ALTER TABLE `' . $table . '` ADD COLUMN `locked_address_id` INT(11) UNSIGNED DEFAULT NULL AFTER `accounting_email`'
        )) {
            return false;
        }
    }

    // 3) Nouveaux hooks de verrouillage d'adresse (registerHook est idempotent).
    if ($module instanceof Module) {
        $module->registerHook('actionObjectAddressUpdateBefore');
        $module->registerHook('actionObjectAddressDeleteBefore');
    }

    return true;
}
