<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Repository\InseeCacheRepository;
use SNT\InscriptionPro\Repository\ViesCacheRepository;
use SNT\InscriptionPro\Service\InseeClient;
use SNT\InscriptionPro\Service\InseeResult;
use SNT\InscriptionPro\Service\Logger;
use SNT\InscriptionPro\Service\SiretValidator;
use SNT\InscriptionPro\Service\VatValidator;
use SNT\InscriptionPro\Service\ViesClient;
use SNT\InscriptionPro\Service\ViesResult;

/**
 * AJAX endpoint appelé au blur du champ SIRET pour vérification INSEE.
 * Réponse strictement en JSON — pas de layout front.
 */
class Snt_inscription_proValidateModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->sendJson($this->buildResponse());
    }

    private function buildResponse(): array
    {
        // Rejet léger des appels hors formulaire (l'en-tête AJAX est posé par
        // notre registration.js). N'empêche pas un abus déterminé, mais filtre
        // le bruit et se combine au rate-limit par IP ci-dessous.
        $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
        if ($requestedWith !== 'xmlhttprequest') {
            return ['httpCode' => 400, 'payload' => ['status' => 'invalid_request']];
        }

        // Flux VIES : vérification d'un n° de TVA intra (pro non-FR).
        if (Tools::getIsset('vat')) {
            return $this->buildViesResponse();
        }

        // Flux recherche par raison sociale + code postal (aide à retrouver son
        // SIRET) : renvoie une liste d'établissements pour un <select> côté client.
        if (Tools::getIsset('rs') || Tools::getIsset('cp')) {
            return $this->buildNameSearchResponse();
        }

        // Flux SIRET : l'utilisateur saisit directement un SIRET (14 chiffres).
        // On interroge l'INSEE en lecture unitaire et on renvoie la raison sociale
        // + l'adresse de l'établissement, pour un aperçu de confirmation côté
        // client. L'adresse siège est ensuite créée d'office au compte (cf.
        // hookValidateCustomerFormFields -> maybeCreateInseeAddress).
        $siret = SiretValidator::normalize((string) Tools::getValue('siret', ''));

        if (!SiretValidator::isSiret($siret)) {
            return ['httpCode' => 400, 'payload' => ['status' => 'invalid_format']];
        }

        // --- Rate-limiting par IP (compteur porté par la table de logs) -------
        $logger = new Logger();
        $ip     = Tools::getRemoteAddr();

        $max = (int) Configuration::get('SNT_IP_RATELIMIT_MAX');
        if ($max <= 0) {
            $max = 10;
        }
        $window = (int) Configuration::get('SNT_IP_RATELIMIT_WINDOW');
        if ($window <= 0) {
            $window = 60;
        }

        $since = date('Y-m-d H:i:s', time() - $window);
        if ($logger->repository()->countInseeCallsByIp($ip, $since) >= $max) {
            $logger->rateLimited($ip, $siret);
            return ['httpCode' => 429, 'payload' => ['status' => 'rate_limited']];
        }

        // Cache serveur d'abord : ce SIRET a pu être vérifié récemment (aperçu
        // rejoué, retour arrière…) — on évite un appel INSEE et on ne le
        // journalise pas (le compteur du journal reste le reflet des appels réels).
        $cache = new InseeCacheRepository();
        $ttl   = (int) Configuration::get('SNT_IP_INSEE_CACHE_TTL');
        $cached = $cache->get($siret, $ttl > 0 ? $ttl : 86400);
        if ($cached !== null) {
            return ['httpCode' => 200, 'payload' => $this->buildSiretPayload($siret, InseeResult::found($cached))];
        }

        // On comptabilise l'appel AVANT de solliciter l'INSEE.
        $logger->inseeCall($ip, $siret);
        // ----------------------------------------------------------------------

        $result = (new InseeClient())->fetchSiret($siret);

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                // Mémorise l'établissement en cache serveur : la re-vérif au submit
                // (applyInseeCheck) le relira au lieu de rappeler l'INSEE, et c'est
                // cette adresse qui alimentera l'adresse siège créée d'office.
                $cache->put($siret, [
                    'company'  => $result->company,
                    'active'   => $result->active,
                    'closed'   => $result->closed,
                    'address1' => $result->address1,
                    'address2' => $result->address2,
                    'postcode' => $result->postcode,
                    'city'     => $result->city,
                ]);
                return ['httpCode' => 200, 'payload' => $this->buildSiretPayload($siret, $result)];

            case InseeResult::STATUS_NOT_FOUND:
                return ['httpCode' => 200, 'payload' => ['status' => 'not_found']];

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
                // Incident de configuration boutique : on trace la raison exacte
                // dans le journal du module (visible au BO) sans l'exposer au front.
                $logger->inseeError(
                    'siret:' . ($result->status === InseeResult::STATUS_INVALID_KEY
                        ? 'invalid_key (clé INSEE absente/invalide)'
                        : 'bad_request'),
                    $siret,
                    $ip
                );
                return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];

            case InseeResult::STATUS_RATE_LIMITED:
            case InseeResult::STATUS_UNAVAILABLE:
            default:
                // Incident transport / rate-limit / clé manquante : on journalise
                // la raison précise (`$result->reason` : missing_api_key, transport,
                // parse, http_XXX…) pour permettre le diagnostic au BO.
                $logger->inseeError(
                    'siret:' . ($result->reason ?: $result->status),
                    $siret,
                    $ip
                );
                if ((bool) Configuration::get('SNT_IP_INSEE_STRICT')) {
                    return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];
                }
                // Mode dégradation gracieuse : SIRET localement valide, mais INSEE
                // injoignable → saisie manuelle de la raison sociale côté client,
                // compte marqué « à vérifier » à la re-vérif serveur.
                return ['httpCode' => 200, 'payload' => ['status' => 'degraded']];
        }
    }

    /**
     * Flux recherche par raison sociale + code postal : aide le client à retrouver
     * son établissement (donc son SIRET). Renvoie une liste d'établissements
     * (fermés exclus si possible), mise en cache serveur pour la re-vérif au
     * submit. Ne fait jamais foi : le SIRET choisi est re-vérifié à la soumission.
     *
     * @return array{httpCode:int, payload:array}
     */
    private function buildNameSearchResponse(): array
    {
        $name     = trim((string) Tools::getValue('rs', ''));
        $postcode = preg_replace('/\D+/', '', (string) Tools::getValue('cp', '')) ?? '';

        // Garde-fou : raison sociale d'au moins 2 caractères + code postal à 5
        // chiffres, pour éviter les recherches trop larges (et coûteuses en quota).
        if (mb_strlen($name) < 2 || strlen($postcode) !== 5) {
            return ['httpCode' => 200, 'payload' => ['status' => 'invalid_format']];
        }

        // --- Rate-limiting par IP (compteur porté par la table de logs) -------
        $logger = new Logger();
        $ip     = Tools::getRemoteAddr();

        $max = (int) Configuration::get('SNT_IP_RATELIMIT_MAX');
        if ($max <= 0) {
            $max = 10;
        }
        $window = (int) Configuration::get('SNT_IP_RATELIMIT_WINDOW');
        if ($window <= 0) {
            $window = 60;
        }
        $since = date('Y-m-d H:i:s', time() - $window);
        if ($logger->repository()->countInseeCallsByIp($ip, $since) >= $max) {
            $logger->rateLimited($ip, $name . ' / ' . $postcode);
            return ['httpCode' => 429, 'payload' => ['status' => 'rate_limited']];
        }
        // On comptabilise l'appel AVANT de solliciter l'INSEE.
        $logger->inseeCall($ip, $name . ' / ' . $postcode);
        // ----------------------------------------------------------------------

        $result = (new InseeClient())->searchByNameAndPostcode($name, $postcode);

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                return ['httpCode' => 200, 'payload' => $this->buildSearchListPayload($result)];

            case InseeResult::STATUS_NOT_FOUND:
                return ['httpCode' => 200, 'payload' => ['status' => 'not_found']];

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
                $logger->inseeError(
                    'search:' . ($result->status === InseeResult::STATUS_INVALID_KEY
                        ? 'invalid_key (clé INSEE absente/invalide)'
                        : 'bad_request'),
                    $name . ' / ' . $postcode,
                    $ip
                );
                return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];

            case InseeResult::STATUS_RATE_LIMITED:
            case InseeResult::STATUS_UNAVAILABLE:
            default:
                $logger->inseeError(
                    'search:' . ($result->reason ?: $result->status),
                    $name . ' / ' . $postcode,
                    $ip
                );
                return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];
        }
    }

    /**
     * Construit le payload `list` : établissements FERMÉS exclus (sans intérêt
     * pour une inscription), triés siège d'abord, plafonnés pour rester
     * exploitables dans un <select>. Garde-fou : si tous sont fermés (entreprise
     * cessée), on les conserve. Les établissements renvoyés sont mémorisés en
     * cache serveur : au submit, la re-vérif du SIRET choisi relira ce cache au
     * lieu de rappeler l'INSEE — et c'est cette adresse qui alimentera l'adresse
     * siège créée d'office.
     *
     * @return array<string,mixed>
     */
    private function buildSearchListPayload(InseeResult $result): array
    {
        $cap = 100;

        $active = array_values(array_filter(
            $result->establishments,
            static function (array $e): bool {
                return empty($e['closed']);
            }
        ));
        $list = !empty($active) ? $active : $result->establishments;

        usort($list, static function (array $a, array $b): int {
            if (($a['siege'] ?? false) !== ($b['siege'] ?? false)) {
                return !empty($a['siege']) ? -1 : 1;
            }
            return strcmp((string) ($a['siret'] ?? ''), (string) ($b['siret'] ?? ''));
        });

        $total     = count($list);
        $truncated = $total > $cap;
        if ($truncated) {
            $list = array_slice($list, 0, $cap);
        }

        (new InseeCacheRepository())->putMany($list);

        return [
            'status'         => 'list',
            'company'        => $result->company,
            'establishments' => $list,
            'total'          => $total,
            'truncated'      => $truncated,
        ];
    }

    /**
     * Flux VIES (pro non-FR) : format -> rate-limit par IP -> cache -> appel VIES.
     * Réponse : {status: valid|invalid|unavailable|invalid_format|rate_limited}.
     * Ne fait jamais confiance à ce blur : la re-vérif serveur au submit fait foi.
     *
     * @return array{httpCode:int, payload:array}
     */
    private function buildViesResponse(): array
    {
        $raw = VatValidator::normalize((string) Tools::getValue('vat', ''));
        $iso = strtoupper(trim((string) Tools::getValue('country', '')));

        if ($raw === '') {
            return ['httpCode' => 400, 'payload' => ['status' => 'invalid_format']];
        }

        [$cc, $num] = VatValidator::split($raw);
        if (!VatValidator::isSupportedCountry($cc)) {
            // Pas de préfixe pays reconnu : on préfixe avec le pays choisi.
            $cc  = VatValidator::isoToViesCountry($iso);
            $num = $raw;
        }
        $full = $cc . $num;
        if ($cc === '' || !VatValidator::isValidFormat($full)) {
            return ['httpCode' => 200, 'payload' => ['status' => 'invalid_format']];
        }

        // --- Rate-limiting par IP (compteur porté par la table de logs) -------
        $logger = new Logger();
        $ip     = Tools::getRemoteAddr();

        $max = (int) Configuration::get('SNT_IP_RATELIMIT_MAX');
        if ($max <= 0) {
            $max = 10;
        }
        $window = (int) Configuration::get('SNT_IP_RATELIMIT_WINDOW');
        if ($window <= 0) {
            $window = 60;
        }
        $since = date('Y-m-d H:i:s', time() - $window);
        if ($logger->repository()->countCallsByIp(Logger::TYPE_VIES_CALL, $ip, $since) >= $max) {
            $logger->rateLimited($ip, null);
            return ['httpCode' => 429, 'payload' => ['status' => 'rate_limited']];
        }

        // Cache VIES d'abord (économie de quota + latence).
        $cache = new ViesCacheRepository();
        $ttl   = (int) Configuration::get('SNT_IP_VIES_CACHE_TTL');
        $cached = $cache->get($full, $ttl > 0 ? $ttl : 604800);
        if ($cached !== null) {
            return ['httpCode' => 200, 'payload' => $cached['valid']
                ? ['status' => 'valid', 'vat' => $full, 'name' => $cached['name'], 'address' => $cached['address']]
                : ['status' => 'invalid', 'vat' => $full]];
        }

        $logger->viesCall($ip, $full);
        $result = (new ViesClient())->checkVat($cc, $num);

        switch ($result->status) {
            case ViesResult::STATUS_VALID:
                $cache->put($full, true, $result->name, $result->address);
                return ['httpCode' => 200, 'payload' => [
                    'status'  => 'valid',
                    'vat'     => $full,
                    'name'    => $result->name,
                    'address' => $result->address,
                ]];

            case ViesResult::STATUS_INVALID:
                $cache->put($full, false, null, null);
                return ['httpCode' => 200, 'payload' => ['status' => 'invalid', 'vat' => $full]];

            case ViesResult::STATUS_BAD_REQUEST:
                return ['httpCode' => 200, 'payload' => ['status' => 'invalid_format']];

            default:
                $logger->viesError($result->reason ?: $result->status, $full, $ip);
                return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];
        }
    }

    /**
     * Construit le payload `found` d'un SIRET : raison sociale + adresse de
     * l'établissement (pour l'aperçu de confirmation côté client). `closed`
     * signale un établissement fermé à l'INSEE (affiché en avertissement, sans
     * bloquer). L'adresse n'est qu'informative ici — elle est recréée d'office
     * côté serveur à partir du cache au moment de la persistance du compte.
     *
     * @return array<string,mixed>
     */
    private function buildSiretPayload(string $siret, InseeResult $result): array
    {
        return [
            'status'  => 'found',
            'siret'   => $siret,
            'siren'   => $result->siren,
            'company' => $result->company,
            'closed'  => $result->closed,
            'address' => [
                'address1' => $result->address1,
                'address2' => $result->address2,
                'postcode' => $result->postcode,
                'city'     => $result->city,
            ],
        ];
    }

    /**
     * @param array{httpCode:int, payload:array} $response
     */
    private function sendJson(array $response): void
    {
        http_response_code($response['httpCode']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        die(json_encode($response['payload']));
    }
}
