<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use SNT\InscriptionPro\Repository\LogRepository;

/**
 * Façade de journalisation interne du module.
 *
 * Règle d'or : le logging ne doit JAMAIS casser un parcours client. Toutes les
 * méthodes encapsulent leurs erreurs (try/catch) et échouent silencieusement.
 * La purge de rétention est déclenchée de façon opportuniste (au plus une fois
 * par jour), sans dépendance à un crontab.
 */
final class Logger
{
    // Types d'événements.
    public const TYPE_ACCOUNT_CREATED  = 'account_created';
    public const TYPE_ACCOUNT_DEGRADED = 'account_degraded';
    public const TYPE_INSEE_CALL       = 'insee_call';
    public const TYPE_INSEE_ERROR      = 'insee_error';
    public const TYPE_API_ACCESS       = 'api_access';
    public const TYPE_RATE_LIMITED     = 'rate_limited';
    public const TYPE_ALERT_MAIL       = 'alert_mail';
    public const TYPE_ADDRESS_LOCKED   = 'address_locked';

    // Sévérités.
    public const SEVERITY_INFO    = 1;
    public const SEVERITY_WARNING = 2;
    public const SEVERITY_ERROR   = 3;

    private const PURGE_INTERVAL   = 86400; // 1 purge / jour max
    private const DEFAULT_RETENTION = 90;

    private LogRepository $repository;

    public function __construct(?LogRepository $repository = null)
    {
        $this->repository = $repository ?? new LogRepository();
    }

    /**
     * @param array{severity?:int, id_customer?:?int, siret?:?string, ip?:?string, message?:?string, context?:mixed} $extra
     */
    public function log(string $type, array $extra = []): void
    {
        try {
            $this->repository->insert(array_merge(['type' => $type], $extra));
            $this->maybePurge();
        } catch (\Throwable $e) {
            // On n'interrompt jamais le parcours pour un problème de log.
        }
    }

    public function accountCreated(int $idCustomer, ?string $siret): void
    {
        $this->log(self::TYPE_ACCOUNT_CREATED, [
            'severity'    => self::SEVERITY_INFO,
            'id_customer' => $idCustomer,
            'siret'       => $siret,
            'message'     => 'Compte pro enregistré',
        ]);
    }

    public function accountDegraded(int $idCustomer, ?string $siret, string $reason): void
    {
        $this->log(self::TYPE_ACCOUNT_DEGRADED, [
            'severity'    => self::SEVERITY_WARNING,
            'id_customer' => $idCustomer,
            'siret'       => $siret,
            'message'     => 'Compte pro accepté sans validation INSEE (à vérifier)',
            'context'     => ['reason' => $reason],
        ]);
    }

    public function inseeCall(?string $ip, ?string $siret): void
    {
        $this->log(self::TYPE_INSEE_CALL, [
            'severity' => self::SEVERITY_INFO,
            'ip'       => $ip,
            'siret'    => $siret,
        ]);
    }

    public function inseeError(string $incident, ?string $siret, ?string $ip = null): void
    {
        $this->log(self::TYPE_INSEE_ERROR, [
            'severity' => self::SEVERITY_ERROR,
            'siret'    => $siret,
            'ip'       => $ip,
            'message'  => 'Incident INSEE : ' . $incident,
            'context'  => ['incident' => $incident],
        ]);
    }

    public function apiAccess(bool $granted, ?int $idCustomer, ?string $ip, string $message): void
    {
        $this->log(self::TYPE_API_ACCESS, [
            'severity'    => $granted ? self::SEVERITY_INFO : self::SEVERITY_WARNING,
            'id_customer' => $idCustomer,
            'ip'          => $ip,
            'message'     => $message,
            'context'     => ['granted' => $granted],
        ]);
    }

    public function rateLimited(?string $ip, ?string $siret): void
    {
        $this->log(self::TYPE_RATE_LIMITED, [
            'severity' => self::SEVERITY_WARNING,
            'ip'       => $ip,
            'siret'    => $siret,
            'message'  => 'Rate limit atteint sur la route de validation SIRET',
        ]);
    }

    public function alertMailSent(string $incident): void
    {
        $this->log(self::TYPE_ALERT_MAIL, [
            'severity' => self::SEVERITY_WARNING,
            'message'  => $incident,
        ]);
    }

    public function repository(): LogRepository
    {
        return $this->repository;
    }

    /**
     * Purge opportuniste : au plus une fois toutes les 24 h, on supprime les
     * logs au-delà de la rétention configurée.
     */
    private function maybePurge(): void
    {
        $last = (int) Configuration::get('SNT_IP_LOG_LAST_PURGE_TS');
        $now  = time();
        if ($last > 0 && ($now - $last) < self::PURGE_INTERVAL) {
            return;
        }

        // On pose le jalon AVANT la purge pour éviter que plusieurs requêtes
        // concurrentes ne lancent le DELETE en même temps.
        Configuration::updateValue('SNT_IP_LOG_LAST_PURGE_TS', (string) $now);

        $retention = (int) Configuration::get('SNT_IP_LOG_RETENTION_DAYS');
        if ($retention <= 0) {
            $retention = self::DEFAULT_RETENTION;
        }
        $this->repository->purgeOlderThan($retention);
    }
}
