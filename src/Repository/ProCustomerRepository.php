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
     * @return array{id_snt_inscription_pro:int,id_customer:int,vatNumber:?string,afe:?string,date_add:string,date_upd:string}|null
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
     */
    public function upsert(int $idCustomer, ?string $vatNumber, ?string $afe): bool
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
                'vatNumber' => $vat,
                'afe'       => $af,
                'date_upd'  => $now,
            ];
            return (bool) $this->db->update(
                self::TABLE,
                $data,
                'id_customer = ' . $idCustomer,
                1
            );
        }

        return (bool) $this->db->insert(self::TABLE, [
            'id_customer' => $idCustomer,
            'vatNumber'   => $vat,
            'afe'         => $af,
            'date_add'    => $now,
            'date_upd'    => $now,
        ]);
    }

    public function deleteByCustomer(int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }
        return (bool) $this->db->delete(self::TABLE, 'id_customer = ' . $idCustomer);
    }
}
