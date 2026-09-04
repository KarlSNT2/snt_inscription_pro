<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Endpoint AJAX « Ne plus afficher ce message » du bandeau d'invitation pro.
 * Le client connecté se déclare particulier : on le retire du groupe
 * « pro à compléter » (SNT_IP_INVITE_GROUP_ID), ce qui masque le bandeau
 * définitivement pour lui.
 *
 * Sécurité : action réservée au client connecté, sur SA propre adhésion, et
 * protégée par un token dérivé du secret serveur (anti-CSRF).
 */
class Snt_inscription_proDismissModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        // Rejet léger des appels hors formulaire (en-tête posé par notre script).
        $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
        if ($requestedWith !== 'xmlhttprequest') {
            http_response_code(400);
            die(json_encode(['status' => 'invalid_request']));
        }

        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged() || (int) $customer->id <= 0) {
            http_response_code(403);
            die(json_encode(['status' => 'not_logged']));
        }

        // Token anti-CSRF : recalculé pour le client connecté et comparé en temps
        // constant. Même formule que côté module (cf. renderProInvite()).
        $expected = self::dismissToken((int) $customer->id);
        if (!hash_equals($expected, (string) Tools::getValue('token'))) {
            http_response_code(403);
            die(json_encode(['status' => 'invalid_token']));
        }

        $group = (int) Configuration::get('SNT_IP_INVITE_GROUP_ID');
        if ($group > 0) {
            Db::getInstance()->delete(
                'customer_group',
                'id_customer = ' . (int) $customer->id . ' AND id_group = ' . $group
            );
        }

        die(json_encode(['status' => 'ok']));
    }

    /**
     * Token dérivé du secret serveur (`Tools::hash` = md5(_COOKIE_KEY_ . data)).
     * DOIT rester identique à celui généré par le module dans renderProInvite().
     */
    public static function dismissToken(int $idCustomer): string
    {
        return substr(Tools::hash('sntip-dismiss-' . $idCustomer), 0, 32);
    }
}
