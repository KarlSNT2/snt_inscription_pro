<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Repository\ProCustomerRepository;
use SNT\InscriptionPro\Service\Logger;

/**
 * Endpoint REST en lecture seule : renvoie `vatNumber`, `afe`,
 * `accounting_email`, `einvoicing_address` (table module) ainsi que `company`
 * et `siret` (fiche client `ps_customer`) pour un `id_customer` donné.
 * Consommé par un ERP / n8n externe.
 *
 * Deux modes selon `id_customer` :
 *  - un id seul (`...&id_customer=42`)        -> objet JSON unique ;
 *  - une liste (`...&id_customer=23,57,75`)   -> tableau JSON d'objets (batch),
 *    limité à MAX_BATCH ids, seuls les clients trouvés sont renvoyés.
 *
 * URL : /index.php?fc=module&module=snt_inscription_pro&controller=api&id_customer=42
 * Auth : header `X-Api-Key`. HTTPS obligatoire. Allowlist IP optionnelle.
 */
class Snt_inscription_proApiModuleFrontController extends ModuleFrontController
{
    /** Nombre maximum d'id_customer acceptés en une requête batch. */
    private const MAX_BATCH = 200;

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

        $raw = trim((string) Tools::getValue('id_customer', ''));

        // Mode BATCH : `id_customer` contient une liste séparée par des virgules
        // (ex. `23,57,75`) -> réponse = tableau d'objets (uniquement les clients
        // trouvés). Mode SIMPLE (un id, sans virgule) -> réponse = objet unique
        // (rétrocompatibilité stricte de l'ancien contrat).
        if (strpos($raw, ',') !== false) {
            $this->respondBatch($raw, $ip);
        }

        $idCustomer = (int) $raw;
        if ($idCustomer <= 0) {
            $this->json(400, ['error' => 'invalid_id_customer'], false, null, $ip, 'id_customer manquant ou invalide');
        }

        $row = (new ProCustomerRepository())->findApiViewByCustomer($idCustomer);
        if ($row === null) {
            // Client sans données pro = cas métier NORMAL, pas une erreur HTTP.
            // On renvoie 200 avec un tableau vide, par cohérence avec le mode
            // batch (respondBatch omet simplement les ids introuvables). Les
            // consommateurs (n8n, ERP) distinguent « trouvé / absent » sur le
            // corps vide, sans avoir à traiter un statut d'erreur.
            $this->json(200, [], true, $idCustomer, $ip, 'Aucune donnée pro pour ce client');
        }

        $row['id_customer'] = $idCustomer;
        $this->json(200, $this->formatRow($row), true, $idCustomer, $ip, 'Accès autorisé');
    }

    /**
     * Mode batch : renvoie un tableau JSON d'objets pour une liste d'id_customer.
     * Seuls les clients possédant des données pro sont présents (les manquants
     * sont simplement omis). 200 même si certains — ou tous — sont absents.
     *
     * @return never
     */
    private function respondBatch(string $raw, string $ip): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            static function ($i) { return $i > 0; }
        )));

        if (empty($ids)) {
            $this->json(400, ['error' => 'invalid_id_customer'], false, null, $ip, 'Liste id_customer vide ou invalide');
        }
        if (count($ids) > self::MAX_BATCH) {
            $this->json(400, ['error' => 'too_many_ids', 'max' => self::MAX_BATCH], false, null, $ip, 'Trop d\'id_customer (max ' . self::MAX_BATCH . ')');
        }

        $rows = (new ProCustomerRepository())->findApiViewByCustomers($ids);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->formatRow($row);
        }

        $this->json(200, $out, true, null, $ip, 'Accès autorisé (batch ' . count($out) . '/' . count($ids) . ')');
    }

    /**
     * Normalise une ligne repository en objet de réponse API.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        return [
            'id_customer'        => (int) ($row['id_customer'] ?? 0),
            'vatNumber'          => $this->str($row, 'vatNumber'),
            'afe'                => $this->str($row, 'afe'),
            'accounting_email'   => $this->str($row, 'accounting_email'),
            'einvoicing_address' => $this->str($row, 'einvoicing_address'),
            'is_administration'  => (bool) ($row['is_administration'] ?? false),
            'company'            => $this->str($row, 'company'),
            'siret'              => $this->str($row, 'siret'),
        ];
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
        // Clé transmise EXCLUSIVEMENT via l'en-tête `X-Api-Key`. On refuse
        // volontairement tout repli en query string (`?key=...`) : la clé s'y
        // retrouverait journalisée par le serveur web, les proxys et le cache
        // navigateur — fuite de secret. Les intégrations doivent positionner
        // l'en-tête HTTP.
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return (string) $_SERVER['HTTP_X_API_KEY'];
        }

        return '';
    }

    /**
     * Normalise une valeur de la ligne en chaîne exposable (ou null si absente).
     *
     * @param array<string,mixed> $row
     */
    private function str(array $row, string $key): ?string
    {
        return isset($row[$key]) && $row[$key] !== null ? (string) $row[$key] : null;
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
