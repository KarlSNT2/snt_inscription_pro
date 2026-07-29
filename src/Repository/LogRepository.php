<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use DbQuery;

/**
 * Accès à la table de journalisation interne du module `ps_snt_inscription_pro_log`.
 * Volontairement autonome (pas d'ObjectModel) pour rester léger et utilisable
 * même en dehors du cycle front complet (endpoint API).
 */
final class LogRepository
{
    public const TABLE = 'snt_inscription_pro_log';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::getInstance();
    }

    /**
     * Insère une entrée de log. Les champs absents sont remplis par des valeurs
     * neutres. Ne lève jamais : renvoie false en cas d'échec.
     *
     * @param array{type:string, severity?:int, id_customer?:?int, siret?:?string, ip?:?string, message?:?string, context?:mixed} $entry
     */
    public function insert(array $entry): bool
    {
        $context = $entry['context'] ?? null;
        if ($context !== null && !is_string($context)) {
            $context = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $data = [
            'type'        => pSQL((string) ($entry['type'] ?? 'info')),
            'severity'    => (int) ($entry['severity'] ?? 1),
            'id_customer' => isset($entry['id_customer']) && $entry['id_customer'] !== null
                ? (int) $entry['id_customer'] : null,
            'siret'       => isset($entry['siret']) && $entry['siret'] !== null
                ? pSQL((string) $entry['siret']) : null,
            'ip'          => isset($entry['ip']) && $entry['ip'] !== null
                ? pSQL((string) $entry['ip']) : null,
            'message'     => isset($entry['message']) && $entry['message'] !== null
                ? pSQL(mb_substr((string) $entry['message'], 0, 255)) : null,
            'context'     => $context !== null ? pSQL((string) $context) : null,
            'date_add'    => date('Y-m-d H:i:s'),
        ];

        return (bool) $this->db->insert(self::TABLE, $data);
    }

    /**
     * Compte les appels INSEE (type `insee_call`) émis par une IP depuis un
     * instant donné. Utilisé par le rate-limiter.
     */
    public function countInseeCallsByIp(string $ip, string $sinceDateTime): int
    {
        $q = new DbQuery();
        $q->select('COUNT(*)')
            ->from(self::TABLE)
            ->where("type = 'insee_call'")
            ->where("ip = '" . pSQL($ip) . "'")
            ->where("date_add >= '" . pSQL($sinceDateTime) . "'");

        return (int) $this->db->getValue($q);
    }

    /**
     * Renvoie true si aucune alerte du type donné n'a été émise depuis
     * `$seconds` secondes (ou jamais) : autorise donc un nouvel envoi.
     * Sert au throttling des mails.
     */
    public function lastAlertOlderThan(string $incident, int $seconds): bool
    {
        $q = new DbQuery();
        $q->select('date_add')
            ->from(self::TABLE)
            ->where("type = 'alert_mail'")
            ->where("message = '" . pSQL($incident) . "'")
            ->orderBy('date_add DESC');
        // `getValue()` ajoute son propre LIMIT 1 — ne pas en remettre.
        $last = $this->db->getValue($q);

        if (!$last) {
            return true;
        }
        return (strtotime((string) $last) + $seconds) < time();
    }

    /**
     * Derniers logs, éventuellement filtrés par type. Destiné à l'affichage BO.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRecent(int $limit = 100, ?string $type = null): array
    {
        $limit = max(1, min($limit, 500));

        $q = new DbQuery();
        $q->select('*')
            ->from(self::TABLE);
        if ($type !== null && $type !== '') {
            $q->where("type = '" . pSQL($type) . "'");
        }
        $q->orderBy('date_add DESC')
            ->limit($limit);

        $rows = $this->db->executeS($q);

        return is_array($rows) ? $rows : [];
    }

    public function countAll(): int
    {
        $q = new DbQuery();
        $q->select('COUNT(*)')->from(self::TABLE);

        return (int) $this->db->getValue($q);
    }

    /**
     * Supprime les logs plus vieux que `$days` jours. Renvoie le nombre de
     * lignes supprimées (0 si rien ou en cas d'échec).
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $threshold = date('Y-m-d H:i:s', time() - ($days * 86400));
        $ok = $this->db->delete(self::TABLE, "date_add < '" . pSQL($threshold) . "'");

        return $ok ? (int) $this->db->Affected_Rows() : 0;
    }
}
