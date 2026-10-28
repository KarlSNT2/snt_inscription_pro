<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.8.x -> 1.9.0.
 *  - Ajoute la clé SNT_IP_ALLOW_INDIVIDUAL (défaut 0) : le choix « Particulier »
 *    est retiré du formulaire d'inscription et refusé côté serveur. Les clients
 *    particuliers déjà inscrits conservent leur statut à l'édition de leur compte.
 *
 * Aucun changement de schéma. Idempotent : la clé n'est créée que si absente.
 */
function upgrade_module_1_9_0($module): bool
{
    if (Configuration::hasKey('SNT_IP_ALLOW_INDIVIDUAL')) {
        return true;
    }

    return (bool) Configuration::updateValue('SNT_IP_ALLOW_INDIVIDUAL', '0');
}
