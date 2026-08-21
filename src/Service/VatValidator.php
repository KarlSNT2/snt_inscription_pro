<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Validation de FORMAT des numéros de TVA intracommunautaires (frère de
 * `SiretValidator`). But : filtrer les saisies manifestement invalides avant
 * de solliciter VIES (service lent + rate-limité). Ne vérifie PAS l'existence
 * réelle — c'est le rôle de `ViesClient`.
 *
 * Couvre l'UE-27 + l'Irlande du Nord (XI). Le Royaume-Uni (GB) est hors VIES
 * depuis le Brexit et n'est volontairement pas listé.
 */
final class VatValidator
{
    /**
     * Regex de la partie NATIONALE (après le code pays à 2 lettres), par code
     * pays VIES. Contrôles de longueur/alphabet uniquement (pas les clés de
     * contrôle nationales, laissées à VIES).
     *
     * @var array<string,string>
     */
    private const PATTERNS = [
        'AT' => '/^U[A-Z0-9]{8}$/',
        'BE' => '/^0\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^[A-Z0-9]{8,9}$/',
        'IT' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'LV' => '/^\d{11}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^[A-Z0-9]{10}[0-9]{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SE' => '/^\d{12}$/',
        'SI' => '/^\d{8}$/',
        'SK' => '/^\d{10}$/',
        'XI' => '/^(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
    ];

    /**
     * Code pays ISO -> code pays VIES quand ils diffèrent. La Grèce est le seul
     * cas courant : ISO « GR » mais préfixe TVA « EL ».
     *
     * @var array<string,string>
     */
    private const ISO_TO_VIES = ['GR' => 'EL'];

    public static function normalize(string $vat): string
    {
        $vat = preg_replace('/[^A-Za-z0-9]/', '', $vat) ?? '';
        return strtoupper($vat);
    }

    /**
     * Sépare un numéro complet en [codePaysVIES, partieNationale].
     *
     * @return array{0:string, 1:string}
     */
    public static function split(string $vat): array
    {
        $vat = self::normalize($vat);
        return [substr($vat, 0, 2), substr($vat, 2)];
    }

    /** Traduit un code pays ISO en code pays VIES (GR -> EL). */
    public static function isoToViesCountry(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        return self::ISO_TO_VIES[$iso] ?? $iso;
    }

    /** Vrai si le pays (code VIES) est couvert par VIES. */
    public static function isSupportedCountry(string $viesCountry): bool
    {
        return isset(self::PATTERNS[strtoupper(trim($viesCountry))]);
    }

    /**
     * Valide le format d'un numéro de TVA complet (code pays inclus).
     */
    public static function isValidFormat(string $vat): bool
    {
        [$cc, $num] = self::split($vat);
        if (!isset(self::PATTERNS[$cc])) {
            return false;
        }
        return (bool) preg_match(self::PATTERNS[$cc], $num);
    }

    /** Liste des codes pays VIES supportés (pour le sélecteur pays). */
    public static function supportedCountries(): array
    {
        return array_keys(self::PATTERNS);
    }
}
