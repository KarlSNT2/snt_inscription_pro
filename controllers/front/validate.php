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
        // On comptabilise l'appel AVANT de solliciter l'INSEE.
        $logger->inseeCall($ip, $siret);
        // ----------------------------------------------------------------------

        $vat = VatCalculator::fromSiret($siret);

        $result = (new InseeClient())->fetchSiret($siret);

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                return ['httpCode' => 200, 'payload' => [
                    'status'  => 'ok',
                    'siren'   => $result->siren,
                    'company' => $result->company,
                    'vat'     => $vat,
                    'closed'  => $result->closed,
                ]];

            case InseeResult::STATUS_NOT_FOUND:
                return ['httpCode' => 200, 'payload' => ['status' => 'not_found']];

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
                // Configuration boutique en défaut : ne pas exposer les détails.
                return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];

            case InseeResult::STATUS_RATE_LIMITED:
            case InseeResult::STATUS_UNAVAILABLE:
            default:
                if ((bool) Configuration::get('SNT_IP_INSEE_STRICT')) {
                    return ['httpCode' => 200, 'payload' => ['status' => 'unavailable']];
                }
                // Mode dégradation gracieuse : SIRET est localement valide.
                return ['httpCode' => 200, 'payload' => [
                    'status' => 'degraded',
                    'vat'    => $vat,
                ]];
        }
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
