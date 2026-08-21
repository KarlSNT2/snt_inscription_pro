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

    /**
     * Recherche multicritère : liste des établissements rattachés à un SIREN.
     * Un seul appel réseau (pagination `nombre` au maximum). Les établissements
     * fermés sont conservés dans la liste mais marqués `closed` (le tri/filtre
     * est laissé aux consommateurs — cf. contrôleur validate).
     */
    public function searchBySiren(string $siren): InseeResult
    {
        $siren = SiretValidator::normalize($siren);
        if (strlen($siren) !== 9) {
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

        // `q=siren:XXXXXXXXX` + pagination max (1000). On restreint la réponse
        // aux seuls champs utiles pour alléger le payload.
        $query = http_build_query([
            'q'      => 'siren:' . $siren,
            'nombre' => 1000,
        ]);

        // ENDPOINT se termine par « /siret/ » (lecture unitaire) ; la recherche
        // multicritère s'adresse à « /siret » sans slash final avant le « ? ».
        $searchUrl = rtrim(self::ENDPOINT, '/') . '?' . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
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
            $this->log('INSEE transport error (search): ' . $curlErr);
            return InseeResult::unavailable('transport');
        }

        switch ($httpCode) {
            case 200:
                return $this->parseSearch(is_string($raw) ? $raw : '');
            case 400:
                return InseeResult::badRequest();
            case 401:
            case 403:
                $this->log('INSEE authentication failure (search, HTTP ' . $httpCode . ')');
                return InseeResult::invalidKey();
            case 404:
                return InseeResult::notFound();
            case 429:
                $this->log('INSEE rate limit hit (search)');
                return InseeResult::rateLimited();
            default:
                $this->log('INSEE unexpected HTTP ' . $httpCode . ' (search)');
                return InseeResult::unavailable('http_' . $httpCode);
        }
    }

    private function parseFound(string $raw): InseeResult
    {
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !isset($decoded->etablissement)) {
            return InseeResult::unavailable('parse');
        }

        $data = $this->extractEtablissement($decoded->etablissement);

        return InseeResult::found([
            'siren'    => $data['siren'],
            'company'  => $data['company'],
            'active'   => $data['active'],
            'closed'   => $data['closed'],
            'address1' => $data['address1'],
            'address2' => $data['address2'],
            'postcode' => $data['postcode'],
            'city'     => $data['city'],
        ]);
    }

    private function parseSearch(string $raw): InseeResult
    {
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !isset($decoded->etablissements) || !is_array($decoded->etablissements)) {
            return InseeResult::unavailable('parse');
        }
        if (count($decoded->etablissements) === 0) {
            return InseeResult::notFound();
        }

        $establishments = [];
        $company        = null;
        $siren          = null;
        foreach ($decoded->etablissements as $et) {
            if (!is_object($et)) {
                continue;
            }
            $data = $this->extractEtablissement($et);
            if ($data['siret'] === null) {
                continue;
            }
            // La raison sociale (unité légale) est commune à tous les
            // établissements : on retient la première non vide.
            if ($company === null && $data['company'] !== null && $data['company'] !== '') {
                $company = $data['company'];
            }
            if ($siren === null && $data['siren'] !== null) {
                $siren = $data['siren'];
            }
            $establishments[] = [
                'siret'    => $data['siret'],
                'siege'    => $data['siege'],
                'active'   => $data['active'],
                'closed'   => $data['closed'],
                'company'  => $data['company'],
                'address1' => $data['address1'],
                'address2' => $data['address2'],
                'postcode' => $data['postcode'],
                'city'     => $data['city'],
            ];
        }

        if (count($establishments) === 0) {
            return InseeResult::notFound();
        }

        return InseeResult::searchFound($establishments, $company, $siren);
    }

    /**
     * Extrait les champs utiles d'un objet `etablissement` (structure identique
     * en lecture unitaire `/siret/{siret}` et en recherche `/siret?q=...`).
     *
     * @return array{siret:?string, siren:?string, company:?string, active:bool, closed:bool, siege:bool, address1:?string, address2:?string, postcode:?string, city:?string}
     */
    private function extractEtablissement(object $et): array
    {
        $siret = isset($et->siret) ? (string) $et->siret : null;
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

        $address = $this->parseAddress($et->adresseEtablissement ?? null);

        return [
            'siret'    => $siret,
            'siren'    => $siren,
            'company'  => $company,
            'active'   => $etat === 'A',
            'closed'   => $etat === 'F',
            'siege'    => !empty($et->etablissementSiege),
            'address1' => $address['address1'],
            'address2' => $address['address2'],
            'postcode' => $address['postcode'],
            'city'     => $address['city'],
        ];
    }

    /**
     * Reconstruit une adresse postale exploitable depuis `adresseEtablissement`
     * (API Sirene 3.11). `address1` concatène numéro + indice de répétition +
     * type + libellé de voie. Repli CEDEX si commune/CP absents.
     *
     * @return array{address1:?string, address2:?string, postcode:?string, city:?string}
     */
    private function parseAddress($adr): array
    {
        $out = ['address1' => null, 'address2' => null, 'postcode' => null, 'city' => null];
        if (!is_object($adr)) {
            return $out;
        }

        $parts = [];
        foreach ([
            'numeroVoieEtablissement',
            'indiceRepetitionEtablissement',
            'typeVoieEtablissement',
            'libelleVoieEtablissement',
        ] as $key) {
            if (!empty($adr->$key)) {
                $parts[] = trim((string) $adr->$key);
            }
        }
        $address1 = trim(implode(' ', $parts));
        $out['address1'] = $address1 !== '' ? $address1 : null;

        if (!empty($adr->complementAdresseEtablissement)) {
            $out['address2'] = trim((string) $adr->complementAdresseEtablissement);
        }

        if (!empty($adr->codePostalEtablissement)) {
            $out['postcode'] = trim((string) $adr->codePostalEtablissement);
        } elseif (!empty($adr->codeCedexEtablissement)) {
            $out['postcode'] = trim((string) $adr->codeCedexEtablissement);
        }

        if (!empty($adr->libelleCommuneEtablissement)) {
            $out['city'] = trim((string) $adr->libelleCommuneEtablissement);
        } elseif (!empty($adr->libelleCedexEtablissement)) {
            $out['city'] = trim((string) $adr->libelleCedexEtablissement);
        }

        return $out;
    }

    private function log(string $message): void
    {
        if (class_exists(PrestaShopLogger::class)) {
            PrestaShopLogger::addLog('[SNT_IP] ' . $message, 2, null, 'SntInscriptionPro');
        }
    }
}
