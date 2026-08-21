<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use DbQuery;

/**
 * Cache serveur des établissements retournés par l'INSEE, indexé sur le SIRET.
 *
 * Objectif : économiser le quota INSEE. Le parcours normal enchaîne deux appels
 * distincts — la recherche par SIREN au blur (`searchBySiren`, contrôleur
 * `validate`) puis la re-vérification du SIRET choisi au submit (`fetchSiret`,
 * `applyInseeCheck`). Comme la recherche par SIREN renvoie déjà les données
 * complètes (raison sociale + adresse) de chaque établissement, on les mémorise
 * ici au blur ; le submit relit le cache au lieu de rappeler l'INSEE.
 *
 * Sécurité : le cache est alimenté exclusivement par des réponses INSEE émises
 * côté serveur — jamais par des données saisies par le client. La valeur reste
 * donc autoritative. Le client ne fournit que le SIRET (clé de 14 chiffres,
 * validée Luhn), qui sert de clé de lecture.
 */
final class InseeCacheRepository
{
    public const TABLE = 'snt_inscription_pro_insee_cache';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::getInstance();
    }

    /**
     * Renvoie l'entrée de cache pour un SIRET si elle existe et n'a pas dépassé
     * le TTL. Le tableau retourné est directement consommable par
     * `InseeResult::found()`.
     *
     * @return array{siren:string, company:?string, active:bool, closed:bool, address1:?string, address2:?string, postcode:?string, city:?string}|null
     */
    public function get(string $siret, int $ttlSeconds): ?array
    {
        $siret = preg_replace('/\D+/', '', $siret) ?? '';
        if (strlen($siret) !== 14 || $ttlSeconds <= 0) {
            return null;
        }

        $since = date('Y-m-d H:i:s', time() - $ttlSeconds);

        $q = new DbQuery();
        $q->select('company, active, closed, address1, address2, postcode, city')
            ->from(self::TABLE)
            ->where("siret = '" . pSQL($siret) . "'")
            ->where("date_add >= '" . pSQL($since) . "'");
        // `getRow()` ajoute son propre LIMIT 1 — ne pas en remettre.
        $row = $this->db->getRow($q);
        if (!$row) {
            return null;
        }

        return [
            'siren'    => substr($siret, 0, 9),
            'company'  => $this->nullableString($row['company'] ?? null),
            'active'   => (bool) ($row['active'] ?? false),
            'closed'   => (bool) ($row['closed'] ?? false),
            'address1' => $this->nullableString($row['address1'] ?? null),
            'address2' => $this->nullableString($row['address2'] ?? null),
            'postcode' => $this->nullableString($row['postcode'] ?? null),
            'city'     => $this->nullableString($row['city'] ?? null),
        ];
    }

    /**
     * Mémorise une liste d'établissements (structure produite par
     * `InseeClient::extractEtablissement`). Upsert par SIRET (REPLACE). Les
     * entrées sans SIRET exploitable sont ignorées.
     *
     * @param array<int,array<string,mixed>> $establishments
     */
    public function putMany(array $establishments): void
    {
        $rows = [];
        $now  = date('Y-m-d H:i:s');
        foreach ($establishments as $e) {
            if (!is_array($e)) {
                continue;
            }
            $siret = preg_replace('/\D+/', '', (string) ($e['siret'] ?? '')) ?? '';
            if (strlen($siret) !== 14) {
                continue;
            }
            $rows[] = [
                'siret'    => pSQL($siret),
                'company'  => $this->pSqlOrNull($e['company']  ?? null),
                'active'   => !empty($e['active']) ? 1 : 0,
                'closed'   => !empty($e['closed']) ? 1 : 0,
                'address1' => $this->pSqlOrNull($e['address1'] ?? null),
                'address2' => $this->pSqlOrNull($e['address2'] ?? null),
                'postcode' => $this->pSqlOrNull($e['postcode'] ?? null),
                'city'     => $this->pSqlOrNull($e['city']     ?? null),
                'date_add' => $now,
            ];
        }
        if (empty($rows)) {
            return;
        }
        // Multi-row REPLACE : rafraîchit `date_add` (donc le TTL) sur les SIRET
        // déjà connus. Ne lève jamais : le cache est un optimiseur, pas un socle.
        try {
            $this->db->insert(self::TABLE, $rows, false, true, Db::REPLACE);
        } catch (\Throwable $e) {
            // Un échec de cache ne doit jamais casser le parcours.
        }
    }

    /**
     * Mémorise un établissement unique (issu de `InseeResult::found`).
     *
     * @param array{company:?string, active:bool, closed:bool, address1:?string, address2:?string, postcode:?string, city:?string} $data
     */
    public function put(string $siret, array $data): void
    {
        $this->putMany([array_merge($data, ['siret' => $siret])]);
    }

    /**
     * Purge les entrées plus vieilles que `$seconds`. Renvoie le nombre de
     * lignes supprimées.
     */
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
