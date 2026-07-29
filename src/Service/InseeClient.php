<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use PrestaShopLogger;

final class InseeClient
{
    private const ENDPOINT = 'https://api.insee.fr/api-sirene/3.11/siret/';

    public function fetchSiret(string $siret): InseeResult
    {
        $siret = SiretValidator::normalize($siret);
        if (strlen($siret) !== 14) {
            return InseeResult::badRequest();
        }

        $apiKey = (string) Configuration::get('SNT_IP_INSEE_API_KEY');
        if ($apiKey === '') {
            $this->log('INSEE API key not configured');
            return InseeResult::unavailable('missing_api_key');
        }

        $timeout = (int) Configuration::get('SNT_IP_INSEE_TIMEOUT');
        if ($timeout <= 0) {
            $timeout = 3;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::ENDPOINT . rawurlencode($siret));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-INSEE-Api-Key-Integration: ' . $apiKey,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode === 0) {
            $this->log('INSEE transport error: ' . $curlErr);
            return InseeResult::unavailable('transport');
        }

        switch ($httpCode) {
            case 200:
                return $this->parseFound(is_string($raw) ? $raw : '');
            case 400:
                return InseeResult::badRequest();
            case 401:
            case 403:
                $this->log('INSEE authentication failure (HTTP ' . $httpCode . ')');
                return InseeResult::invalidKey();
            case 404:
                return InseeResult::notFound();
            case 429:
                $this->log('INSEE rate limit hit');
                return InseeResult::rateLimited();
            default:
                $this->log('INSEE unexpected HTTP ' . $httpCode);
                return InseeResult::unavailable('http_' . $httpCode);
        }
    }

    private function parseFound(string $raw): InseeResult
    {
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !isset($decoded->etablissement)) {
            return InseeResult::unavailable('parse');
        }

        $et    = $decoded->etablissement;
        $siren = isset($et->siren) ? (string) $et->siren : null;

        $unite   = $et->uniteLegale ?? null;
        $company = null;
        if (is_object($unite)) {
            if (!empty($unite->denominationUniteLegale)) {
                $company = trim((string) $unite->denominationUniteLegale);
            } else {
                $prenom = (string) ($unite->prenomUsuelUniteLegale
                    ?? $unite->prenom1UniteLegale
                    ?? '');
                $nom = (string) ($unite->nomUniteLegale ?? '');
                $full = trim($prenom . ' ' . $nom);
                if ($full !== '') {
                    $company = $full;
                }
            }
        }

        $etat = '';
        if (!empty($et->periodesEtablissement)
            && is_array($et->periodesEtablissement)
            && isset($et->periodesEtablissement[0]->etatAdministratifEtablissement)
        ) {
            $etat = (string) $et->periodesEtablissement[0]->etatAdministratifEtablissement;
        }

        return InseeResult::found([
            'siren'   => $siren,
            'company' => $company,
            'active'  => $etat === 'A',
            'closed'  => $etat === 'F',
        ]);
    }

    private function log(string $message): void
    {
        if (class_exists(PrestaShopLogger::class)) {
            PrestaShopLogger::addLog('[SNT_IP] ' . $message, 2, null, 'SntInscriptionPro');
        }
    }
}
