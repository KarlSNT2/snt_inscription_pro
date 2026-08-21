<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use PrestaShopLogger;

/**
 * Client de vérification VIES (VAT Information Exchange System, Commission
 * européenne). Aucune clé API : service public gratuit. On utilise l'API REST
 * JSON (pas de dépendance SOAP), en cURL, avec timeout court — homogène avec
 * `InseeClient`.
 *
 * VIES est lent et instable (indispos par État membre, rate-limit par IP) : tout
 * incident transitoire renvoie un statut `UNAVAILABLE`/`RATE_LIMITED`, à traiter
 * en mode dégradé côté appelant (jamais comme un refus).
 */
final class ViesClient
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /**
     * Vérifie un numéro de TVA. `$countryCode` = code pays VIES (EL pour la
     * Grèce) ; `$vatNumber` = partie nationale (le code pays est retiré s'il est
     * présent en tête).
     */
    public function checkVat(string $countryCode, string $vatNumber): ViesResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber   = VatValidator::normalize($vatNumber);

        // Tolérance : si le numéro national est préfixé du code pays, on l'enlève.
        if (strlen($vatNumber) > 2 && substr($vatNumber, 0, 2) === $countryCode) {
            $vatNumber = substr($vatNumber, 2);
        }
        if (strlen($countryCode) !== 2 || $vatNumber === '') {
            return ViesResult::badRequest();
        }

        $timeout = (int) Configuration::get('SNT_IP_VIES_TIMEOUT');
        if ($timeout <= 0) {
            $timeout = 5;
        }

        $payload = json_encode(['countryCode' => $countryCode, 'vatNumber' => $vatNumber]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::ENDPOINT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'content-type: application/json',
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode === 0) {
            $this->log('VIES transport error: ' . $curlErr);
            return ViesResult::unavailable('transport');
        }

        switch ($httpCode) {
            case 200:
                return $this->parse(is_string($raw) ? $raw : '', $countryCode, $vatNumber);
            case 400:
                return ViesResult::badRequest();
            case 429:
                $this->log('VIES rate limit hit');
                return ViesResult::rateLimited();
            default:
                $this->log('VIES unexpected HTTP ' . $httpCode);
                return ViesResult::unavailable('http_' . $httpCode);
        }
    }

    /**
     * Parse la réponse JSON. Happy path : `{valid:true|false, name, address,
     * requestDate}`. Les indisponibilités d'un État membre peuvent arriver sous
     * forme d'`errorWrappers[]` ou `userError` — traitées en incident
     * transitoire. Toute structure inattendue → `unavailable('parse')` (donc
     * mode dégradé, jamais un faux refus).
     */
    private function parse(string $raw, string $countryCode, string $vatNumber): ViesResult
    {
        $d = json_decode($raw);
        if (!is_object($d)) {
            return ViesResult::unavailable('parse');
        }

        // Incidents renvoyés en 200 selon les versions de l'API.
        if (!empty($d->errorWrappers) && is_array($d->errorWrappers)) {
            $err = strtoupper((string) ($d->errorWrappers[0]->error ?? 'UNKNOWN'));
            return $this->mapError($err);
        }
        if (isset($d->userError) && !in_array((string) $d->userError, ['VALID', 'INVALID'], true)) {
            return $this->mapError(strtoupper((string) $d->userError));
        }

        if (isset($d->valid)) {
            if ($d->valid === true) {
                return ViesResult::valid([
                    'countryCode'       => $countryCode,
                    'vatNumber'         => $vatNumber,
                    'name'              => isset($d->name) ? trim((string) $d->name) : null,
                    'address'           => isset($d->address) ? trim((string) $d->address) : null,
                    'requestDate'       => isset($d->requestDate) ? (string) $d->requestDate : null,
                    'requestIdentifier' => isset($d->requestIdentifier) ? (string) $d->requestIdentifier : null,
                ]);
            }
            return ViesResult::invalid($countryCode, $vatNumber);
        }

        return ViesResult::unavailable('parse');
    }

    private function mapError(string $code): ViesResult
    {
        if (in_array($code, ['MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ'], true)) {
            $this->log('VIES concurrency limit: ' . $code);
            return ViesResult::rateLimited();
        }
        $this->log('VIES member-state error: ' . $code);
        return ViesResult::unavailable('vies_' . strtolower($code));
    }

    private function log(string $message): void
    {
        if (class_exists(PrestaShopLogger::class)) {
            PrestaShopLogger::addLog('[SNT_IP] ' . $message, 2, null, 'SntInscriptionPro');
        }
    }
}
