<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use DbQuery;

final class ProCustomerRepository
{
    private const TABLE = 'snt_inscription_pro';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::getInstance();
    }

    /**
     * @return array{id_snt_inscription_pro:int,id_customer:int,vatNumber:?string,afe:?string,needs_review:int,date_add:string,date_upd:string}|null
     */
    public function findByCustomer(int $idCustomer): ?array
    {
        if ($idCustomer <= 0) {
            return null;
        }
        $q = new DbQuery();
        $q->select('*')
            ->from(self::TABLE)
            ->where('id_customer = ' . $idCustomer);
        // Note : `Db::getRow()` ajoute lui-même un `LIMIT 1` à la requête ;
        // ne pas rajouter `->limit(1)` ici (double LIMIT = erreur SQL).
        $row = $this->db->getRow($q);
        return $row ?: null;
    }

    /**
     * Upsert atomique sur (id_customer). Retourne true si l'écriture a réussi.
     *
     * @param bool $needsReview Marque le compte « à vérifier » (INSEE indisponible
     *                          au moment de la création → raison sociale non
     *                          authentifiée).
     */
    public function upsert(int $idCustomer, ?string $vatNumber, ?string $afe, bool $needsReview = false): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }
        $vat = $vatNumber !== null ? pSQL($vatNumber) : null;
        $af  = $afe       !== null ? pSQL($afe)       : null;
        $now = date('Y-m-d H:i:s');

        $existing = $this->findByCustomer($idCustomer);
        if ($existing) {
            $data = [
                'vatNumber'    => $vat,
                'afe'          => $af,
                'needs_review' => $needsReview ? 1 : 0,
                'date_upd'     => $now,
            ];
            return (bool) $this->db->update(
                self::TABLE,
                $data,
                'id_customer = ' . $idCustomer,
                1
            );
        }

        return (bool) $this->db->insert(self::TABLE, [
            'id_customer'  => $idCustomer,
            'vatNumber'    => $vat,
            'afe'          => $af,
            'needs_review' => $needsReview ? 1 : 0,
            'date_add'     => $now,
            'date_upd'     => $now,
        ]);
    }

    /**
     * Comptes pro marqués « à vérifier » (raison sociale non authentifiée INSEE),
     * enrichis du nom/e-mail client pour l'affichage BO. Les plus récents d'abord.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findNeedsReview(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));

        $q = new DbQuery();
        $q->select('p.id_customer, p.vatNumber, p.afe, p.date_upd, c.firstname, c.lastname, c.email, c.company, c.siret')
            ->from(self::TABLE, 'p')
            ->leftJoin('customer', 'c', 'c.id_customer = p.id_customer')
            ->where('p.needs_review = 1')
            ->orderBy('p.date_upd DESC')
            ->limit($limit);

        $rows = $this->db->executeS($q);

        return is_array($rows) ? $rows : [];
    }

    public function deleteByCustomer(int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }
        return (bool) $this->db->delete(self::TABLE, 'id_customer = ' . $idCustomer);
    }
}
