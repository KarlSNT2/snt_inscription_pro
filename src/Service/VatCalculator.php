<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class VatCalculator
{
    /**
     * Calcule le numéro de TVA intracommunautaire français depuis un SIREN.
     * Formule : FR + [ (12 + 3 * (SIREN % 97)) % 97 ] + SIREN
     */
    public static function fromSiren(string $siren): ?string
    {
        $siren = SiretValidator::normalize($siren);
        if (strlen($siren) < 9) {
            return null;
        }
        $siren = substr($siren, 0, 9);
        $sirenInt = (int) $siren;
        $key = (12 + 3 * ($sirenInt % 97)) % 97;
        return 'FR' . str_pad((string) $key, 2, '0', STR_PAD_LEFT) . $siren;
    }

    public static function fromSiret(string $siret): ?string
    {
        return self::fromSiren(substr(SiretValidator::normalize($siret), 0, 9));
    }
}
