<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Service\InseeClient;
use SNT\InscriptionPro\Service\InseeResult;
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
        $siret = SiretValidator::normalize((string) Tools::getValue('siret', ''));

        if (!SiretValidator::isSiret($siret)) {
            return ['httpCode' => 400, 'payload' => ['status' => 'invalid_format']];
        }

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
