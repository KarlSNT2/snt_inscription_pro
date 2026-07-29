<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Squelette d'upgrade 1.0.0 -> 1.0.1.
 * Ajouter ici les DDL / migrations de configuration pour la version cible.
 */
function upgrade_module_1_0_1($module): bool
{
    unset($module);
    return true;
}
