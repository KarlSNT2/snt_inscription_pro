<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Service\InseeClient;
use SNT\InscriptionPro\Service\InseeResult;
use SNT\InscriptionPro\Service\Logger;
use SNT\InscriptionPro\Service\SiretValidator;
use SNT\InscriptionPro\Service\VatCalculator;

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

        // Flux SIREN : l'utilisateur saisit un SIREN (9 chiffres), on renvoie la
        // liste de ses établissements pour alimenter le <select> côté client.
        $siren = SiretValidator::normalize((string) Tools::getValue('siren', ''));

        if (!SiretValidator::isSiren($siren)) {
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
            $logger->rateLimited($ip, $siren);
            return ['httpCode' => 429, 'payload' => ['status' => 'rate_limited']];
        }
        // On comptabilise l'appel AVANT de solliciter l'INSEE.
        $logger->inseeCall($ip, $siren);
        // ----------------------------------------------------------------------

        $vat = VatCalculator::fromSiren($siren);

        $result = (new InseeClient())->searchBySiren($siren);

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                return ['httpCode' => 200, 'payload' => $this->buildFoundPayload($result, $vat)];

            case InseeResult::STATUS_NOT_FOUND:
                return ['httpCode' => 200, 'payload' => ['status' => 'not_found']];

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
                // Incident de configuration boutique : on trace la raison exacte
                // dans le journal du module (visible au BO) sans l'exposer au front.
                $logger->inseeError(
                    'search:' . ($result->status === InseeResult::STATUS_INVALID_KEY
                        ? 'invalid_key (clé INSEE absente/invalide)'
                        : 'bad_request'),
                    $siren,
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
                    'search:' . ($result->reason ?: $result->status),
                    $siren,
                    $ip
                );
                if ((bool) Configuration::get('SNT_IP_INSEE_STRICT')) {
                    return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];
                }
                // Mode dégradation gracieuse : SIREN localement valide, mais
                // impossible de lister les établissements → saisie manuelle du
                // SIRET côté client.
                return ['httpCode' => 200, 'payload' => [
                    'status' => 'degraded',
                    'vat'    => $vat,
                ]];
        }
    }

    /**
     * Construit le payload `ok` : établissements FERMÉS exclus (sans intérêt pour
     * une inscription / facturation), triés siège d'abord, plafonnés pour rester
     * exploitables dans un <select>. `truncated` signale un dépassement du
     * plafond. Garde-fou : si tous les établissements sont fermés (entreprise
     * cessée), on les conserve pour ne pas bloquer le parcours.
     *
     * @return array<string,mixed>
     */
    private function buildFoundPayload(InseeResult $result, ?string $vat): array
    {
        $cap = 200;

        // Filtrage des établissements fermés (etatAdministratif = F).
        $active = array_values(array_filter(
            $result->establishments,
            static function (array $e): bool {
                return empty($e['closed']);
            }
        ));
        $list = !empty($active) ? $active : $result->establishments;

        usort($list, static function (array $a, array $b): int {
            // Siège en premier.
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

        return [
            'status'         => 'ok',
            'siren'          => $result->siren,
            'company'        => $result->company,
            'vat'            => $vat,
            'establishments' => $list,
            'total'          => $total,
            'truncated'      => $truncated,
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
