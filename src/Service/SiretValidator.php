<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SiretValidator
{
    /**
     * SIREN connus qui ne passent pas Luhn mais sont valides.
     * Cf. La Poste : 356 000 000.
     */
    private const LUHN_EXCEPTIONS_SIREN = ['356000000'];

    public static function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function isSiret(string $siret): bool
    {
        $siret = self::normalize($siret);
        if (strlen($siret) !== 14) {
            return false;
        }
        $siren = substr($siret, 0, 9);
        if (in_array($siren, self::LUHN_EXCEPTIONS_SIREN, true)) {
            // Établissements de La Poste : le SIRET (14) doit être multiple de 5 (cf. INSEE).
            return ((int) $siret) % 5 === 0;
        }
        return self::luhn($siret);
    }

    public static function isSiren(string $siren): bool
    {
        $siren = self::normalize($siren);
        if (strlen($siren) !== 9) {
            return false;
        }
        if (in_array($siren, self::LUHN_EXCEPTIONS_SIREN, true)) {
            return true;
        }
        return self::luhn($siren);
    }

    public static function extractSiren(string $siret): string
    {
        return substr(self::normalize($siret), 0, 9);
    }

    private static function luhn(string $digits): bool
    {
        $sum    = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $d = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }
}
