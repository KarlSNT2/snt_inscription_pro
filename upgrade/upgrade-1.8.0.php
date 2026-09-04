<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Migration 1.7.x -> 1.8.0.
 *  - Enregistre deux hooks d'affichage front pour le bandeau d'invitation
 *    « mettez à jour vos informations pro » destiné aux clients professionnels
 *    pré-existants (raison sociale sur la fiche client, mais aucune donnée dans
 *    ps_snt_inscription_pro) :
 *      * displayShoppingCartFooter : page panier ;
 *      * displayCheckoutSummaryTop : tunnel de commande.
 *
 * Aucun changement de schéma. Idempotent : registerHook ne crée pas de doublon
 * si le hook est déjà enregistré.
 */
function upgrade_module_1_8_0($module): bool
{
    $ok = true;
    foreach (['displayShoppingCartFooter', 'displayCheckoutSummaryTop'] as $hook) {
        $ok = (bool) $module->registerHook($hook) && $ok;
    }

    return $ok;
}
