<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Mail;

/**
 * Envoi (throttlé) d'alertes e-mail au support lorsqu'un incident INSEE empêche
 * la vérification d'un SIRET et qu'un compte pro est accepté en mode dégradé.
 *
 * Anti-flood : au plus un mail par `SNT_IP_ALERT_THROTTLE` secondes et par type
 * d'incident, quel que soit le nombre d'inscriptions concernées.
 */
final class MailAlerter
{
    private const INCIDENT_KEY   = 'insee_down';
    private const TEMPLATE       = 'snt_insee_alert';
    private const DEFAULT_THROTTLE = 3600;

    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Notifie le support d'un incident INSEE. Ne fait rien si un mail identique
     * a déjà été envoyé dans la fenêtre de throttling. Ne lève jamais.
     *
     * @param array<string,mixed> $context Détails (siret, id_customer…) pour le corps du mail.
     */
    public function notifyInseeDown(string $incident, array $context = []): void
    {
        try {
            $throttle = (int) Configuration::get('SNT_IP_ALERT_THROTTLE');
            if ($throttle <= 0) {
                $throttle = self::DEFAULT_THROTTLE;
            }

            if (!$this->logger->repository()->lastAlertOlderThan(self::INCIDENT_KEY, $throttle)) {
                return; // throttlé : un mail récent couvre déjà l'incident
            }

            $recipient = trim((string) Configuration::get('SNT_IP_SUPPORT_EMAIL'));
            if ($recipient === '') {
                $recipient = (string) Configuration::get('PS_SHOP_EMAIL');
            }
            if ($recipient === '' || !\Validate::isEmail($recipient)) {
                return;
            }

            $shopName = (string) Configuration::get('PS_SHOP_NAME');
            $idLang   = (int) Configuration::get('PS_LANG_DEFAULT');

            $sent = Mail::Send(
                $idLang,
                self::TEMPLATE,
                sprintf('[%s] Incident INSEE — vérification SIRET indisponible', $shopName),
                [
                    '{incident}'  => $incident,
                    '{siret}'     => (string) ($context['siret'] ?? '—'),
                    '{date}'      => date('Y-m-d H:i:s'),
                    '{shop_name}' => $shopName,
                ],
                $recipient,
                null,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'snt_inscription_pro/mails/',
                false,
                (int) Context::getContext()->shop->id
            );

            // On journalise l'envoi (sert aussi de jalon de throttling).
            if ($sent) {
                $this->logger->alertMailSent(self::INCIDENT_KEY);
            }
        } catch (\Throwable $e) {
            // Une alerte qui échoue ne doit jamais bloquer une inscription.
        }
    }
}
