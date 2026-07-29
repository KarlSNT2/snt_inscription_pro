<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Repository\ProCustomerRepository;
use SNT\InscriptionPro\Service\Logger;

/**
 * Endpoint REST en lecture seule : renvoie `vatNumber` + `afe` pour un
 * `id_customer` donné. Consommé par un ERP / n8n externe.
 *
 * `siret` et `company` ne sont volontairement PAS exposés ici : ils sont déjà
 * couverts par le webservice PrestaShop natif.
 *
 * URL : /index.php?fc=module&module=snt_inscription_pro&controller=api&id_customer=42
 * Auth : header `X-Api-Key`. HTTPS obligatoire. Allowlist IP optionnelle.
 */
class Snt_inscription_proApiModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $auth = false;
    public $ssl = true;

    /**
     * On court-circuite tout le cycle front (template, session, cookies) pour
     * un endpoint léger : la réponse JSON est émise directement puis `exit`.
     */
    public function init()
    {
        try {
            $this->respond();
        } catch (\Throwable $e) {
            $this->json(500, ['error' => 'server_error'], false, null);
        }
    }

    private function respond(): void
    {
        $ip = Tools::getRemoteAddr();

        if (!$this->isHttps()) {
            $this->json(403, ['error' => 'https_required'], false, null, $ip, 'Requête non HTTPS refusée');
        }

        if (!$this->isIpAllowed($ip)) {
            $this->json(403, ['error' => 'ip_forbidden'], false, null, $ip, 'IP hors allowlist');
        }

        $expected = (string) Configuration::get('SNT_IP_API_KEY');
        $provided = $this->providedKey();
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->json(401, ['error' => 'unauthorized'], false, null, $ip, 'Clé API absente ou invalide');
        }

        $idCustomer = (int) Tools::getValue('id_customer', 0);
        if ($idCustomer <= 0) {
            $this->json(400, ['error' => 'invalid_id_customer'], false, null, $ip, 'id_customer manquant ou invalide');
        }

        $row = (new ProCustomerRepository())->findByCustomer($idCustomer);
        if ($row === null) {
            $this->json(404, ['error' => 'not_found'], false, $idCustomer, $ip, 'Aucune donnée pro pour ce client');
        }

        $this->json(200, [
            'id_customer' => $idCustomer,
            'vatNumber'   => isset($row['vatNumber']) && $row['vatNumber'] !== null ? (string) $row['vatNumber'] : null,
            'afe'         => isset($row['afe']) && $row['afe'] !== null ? (string) $row['afe'] : null,
        ], true, $idCustomer, $ip, 'Accès autorisé');
    }

    private function isHttps(): bool
    {
        if (Tools::usingSecureMode()) {
            return true;
        }
        $proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) : '';

        return $proto === 'https';
    }

    private function isIpAllowed(string $ip): bool
    {
        $csv = trim((string) Configuration::get('SNT_IP_API_IP_ALLOWLIST'));
        if ($csv === '') {
            return true; // pas de filtre configuré
        }
        $allowed = array_filter(array_map('trim', explode(',', $csv)), static function ($v) {
            return $v !== '';
        });

        return in_array($ip, $allowed, true);
    }

    private function providedKey(): string
    {
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return (string) $_SERVER['HTTP_X_API_KEY'];
        }
        // Repli : certains proxys/clients ne peuvent pas positionner l'en-tête.
        return (string) Tools::getValue('key', '');
    }

    /**
     * Émet la réponse JSON, journalise l'accès puis termine la requête.
     * La clé API n'est jamais journalisée.
     *
     * @param array<string,mixed> $payload
     * @return never
     */
    private function json(int $code, array $payload, bool $granted, ?int $idCustomer, ?string $ip = null, string $message = ''): void
    {
        try {
            (new Logger())->apiAccess($granted, $idCustomer, $ip, 'API ' . $code . ' — ' . $message);
        } catch (\Throwable $e) {
            // ne jamais bloquer la réponse pour un problème de log
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        http_response_code($code);
        echo json_encode($payload);
        exit;
    }
}
