<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use DbQuery;
use SNT\InscriptionPro\Service\VatValidator;

/**
 * Cache serveur des vérifications VIES, indexé sur le numéro de TVA complet
 * (code pays + partie nationale, normalisé). Miroir de `InseeCacheRepository`.
 *
 * VIES étant lent (1–10 s) et rate-limité par IP, le cache est ici encore plus
 * critique que pour l'INSEE : il évite de re-vérifier un numéro déjà vu (blur
 * puis submit, ou plusieurs clients de la même société). Un TTL long est adapté
 * (le statut intracom. d'une entreprise change rarement).
 */
final class ViesCacheRepository
{
    public const TABLE = 'snt_inscription_pro_vies_cache';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::getInstance();
    }

    /**
     * Entrée de cache pour un numéro complet, si présente et dans le TTL.
     *
     * @return array{valid:bool, name:?string, address:?string}|null
     */
    public function get(string $vatNumber, int $ttlSeconds): ?array
    {
        $vat = VatValidator::normalize($vatNumber);
        if ($vat === '' || $ttlSeconds <= 0) {
            return null;
        }
        $since = date('Y-m-d H:i:s', time() - $ttlSeconds);

        $q = new DbQuery();
        $q->select('valid, name, address')
            ->from(self::TABLE)
            ->where("vat_number = '" . pSQL($vat) . "'")
            ->where("date_add >= '" . pSQL($since) . "'");
        $row = $this->db->getRow($q);
        if (!$row) {
            return null;
        }

        return [
            'valid'   => (bool) ($row['valid'] ?? false),
            'name'    => $this->nullableString($row['name'] ?? null),
            'address' => $this->nullableString($row['address'] ?? null),
        ];
    }

    /**
     * Mémorise (upsert par numéro) le résultat d'une vérification définitive
     * (valide ou invalide). Ne jamais mettre en cache un incident transitoire.
     * Ne lève jamais : le cache est un optimiseur, pas un socle.
     */
    public function put(string $vatNumber, bool $valid, ?string $name, ?string $address): void
    {
        $vat = VatValidator::normalize($vatNumber);
        if ($vat === '') {
            return;
        }
        $data = [
            'vat_number' => pSQL($vat),
            'valid'      => $valid ? 1 : 0,
            'name'       => $this->pSqlOrNull($name),
            'address'    => $this->pSqlOrNull($address),
            'date_add'   => date('Y-m-d H:i:s'),
        ];
        try {
            $this->db->insert(self::TABLE, $data, false, true, Db::REPLACE);
        } catch (\Throwable $e) {
            // Un échec de cache ne doit jamais casser le parcours.
        }
    }

    public function purgeOlderThan(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }
        $threshold = date('Y-m-d H:i:s', time() - $seconds);
        $ok = $this->db->delete(self::TABLE, "date_add < '" . pSQL($threshold) . "'");

        return $ok ? (int) $this->db->Affected_Rows() : 0;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value !== '' ? $value : null;
    }

    private function pSqlOrNull($value): ?string
    {
        $value = $this->nullableString($value);

        return $value !== null ? pSQL($value) : null;
    }
}
