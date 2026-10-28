<?php
/**
 * SNT Inscription Pro
 * Enrichissement du formulaire d'inscription PrestaShop pour la facturation
 * électronique française (SIRET vérifié INSEE, TVA intracom., AFE), sans
 * activation du mode B2B global ni override du thème.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use SNT\InscriptionPro\Repository\InseeCacheRepository;
use SNT\InscriptionPro\Repository\ProCustomerRepository;
use SNT\InscriptionPro\Service\InseeClient;
use SNT\InscriptionPro\Service\InseeResult;
use SNT\InscriptionPro\Service\Logger;
use SNT\InscriptionPro\Service\MailAlerter;
use SNT\InscriptionPro\Service\SiretValidator;
use SNT\InscriptionPro\Repository\ViesCacheRepository;
use SNT\InscriptionPro\Service\ViesClient;
use SNT\InscriptionPro\Service\ViesResult;
use SNT\InscriptionPro\Service\VatValidator;

class Snt_Inscription_Pro extends Module
{
    public const CONFIG_KEYS = [
        'SNT_IP_INSEE_API_KEY'     => '',
        'SNT_IP_INSEE_TIMEOUT'     => '3',
        'SNT_IP_API_KEY'           => '', // généré à l'install
        'SNT_IP_API_IP_ALLOWLIST'  => '',
        'SNT_IP_SIRET_REQUIRED'    => '1',
        'SNT_IP_AFE_REGEX'         => '^\\d{9}$',
        'SNT_IP_WRITE_COMPANY'     => '1',
        'SNT_IP_COMPANY_EDITABLE'  => '0',
        'SNT_IP_INSEE_STRICT'      => '0',
        'SNT_IP_PURGE_ON_DOWNGRADE'=> '0',
        'SNT_IP_SUPPORT_EMAIL'     => '',   // vide => PS_SHOP_EMAIL
        'SNT_IP_RATELIMIT_MAX'     => '10', // appels INSEE max / fenêtre / IP
        'SNT_IP_RATELIMIT_WINDOW'  => '60', // fenêtre du rate-limit (s)
        'SNT_IP_LOG_RETENTION_DAYS'=> '90', // rétention des logs (jours)
        'SNT_IP_ALERT_THROTTLE'    => '3600', // anti-flood mails d'alerte (s)
        'SNT_IP_INSEE_CACHE_TTL'   => '86400', // durée de vie du cache INSEE (s)
        'SNT_IP_VIES_ENABLE'       => '1',      // activer la vérification VIES
        'SNT_IP_VIES_STRICT'       => '0',      // bloquer si VIES injoignable
        'SNT_IP_VIES_TIMEOUT'      => '5',      // timeout appel VIES (s)
        'SNT_IP_VIES_CACHE_TTL'    => '604800', // durée de vie du cache VIES (s) — 7 j
        // Groupe client « pro à compléter » : les clients qui avaient une raison
        // sociale (reset lors de la migration) y sont ajoutés ; le bandeau
        // d'invitation ne s'affiche qu'aux membres de ce groupe. 0 => désactivé.
        'SNT_IP_INVITE_GROUP_ID'   => '0',
        // Choix « Particulier » à l'inscription. 0 => seuls les comptes pro
        // (Professionnel / Administration) peuvent être créés ; les particuliers
        // déjà inscrits conservent leur statut à l'édition de leur compte.
        'SNT_IP_ALLOW_INDIVIDUAL'  => '0',
    ];

    private const HOOKS = [
        'additionalCustomerFormFields',
        'validateCustomerFormFields',
        'actionCustomerAccountAdd',
        'actionCustomerAccountUpdate',
        'actionObjectCustomerDeleteAfter',
        'actionFrontControllerSetMedia',
        'actionObjectAddressUpdateBefore',
        'actionObjectAddressDeleteBefore',
        // Bandeau d'invitation « mettez à jour vos infos pro » pour les clients
        // pro pré-existants (raison sociale sur la fiche client, mais aucune donnée
        // dans la table du module). Affiché sur le panier et dans le tunnel.
        'displayShoppingCartFooter',
        'displayCheckoutSummaryTop',
    ];

    private ?ProCustomerRepository $repository = null;
    private ?Logger $logger = null;
    private ?MailAlerter $mailAlerter = null;

    /**
     * Porté entre `hookValidateCustomerFormFields` (décision INSEE) et
     * `hookActionCustomerAccountAdd/Update` (persistance) dans la même requête :
     * true si le compte est accepté sans validation INSEE et doit être vérifié.
     */
    private bool $pendingNeedsReview = false;

    /**
     * Porté de la même façon : adresse de l'établissement renvoyée par l'INSEE
     * (STATUS_FOUND avec composants exploitables). Consommée par
     * `hookActionCustomerAccountAdd` pour créer d'office l'adresse « siège social »
     * du client pro. Vide si l'INSEE n'a pas confirmé ou n'a pas fourni d'adresse.
     *
     * @var array{address1:string, address2:string, postcode:string, city:string, siren:?string}|array{}
     */
    private array $pendingInseeAddress = [];

    public function __construct()
    {
        $this->name          = 'snt_inscription_pro';
        $this->tab           = 'front_office_features';
        $this->version       = '1.9.0';
        $this->author        = 'SNT2';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName      = $this->trans('SNT Inscription Pro', [], 'Modules.SntInscriptionPro.Admin');
        $this->description      = $this->trans(
            'Enrichit l\'inscription client avec les données professionnelles (SIRET INSEE, TVA, AFE) sans activer le mode B2B ni modifier le thème.',
            [], 'Modules.SntInscriptionPro.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Confirmer la désinstallation ? Vous pourrez choisir de conserver ou purger les données pro clients.',
            [], 'Modules.SntInscriptionPro.Admin'
        );

        $this->registerAutoloader();
    }

    // ------------------------------------------------------------------
    // Autoloader PSR-4 minimal pour src/
    // ------------------------------------------------------------------

    private function registerAutoloader(): void
    {
        $prefix  = 'SNT\\InscriptionPro\\';
        $baseDir = __DIR__ . '/src/';
        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    // ------------------------------------------------------------------
    // Installation / désinstallation
    // ------------------------------------------------------------------

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        if (!$this->installTable() || !$this->installLogTable()
            || !$this->installCacheTable() || !$this->installViesCacheTable()
            || !$this->ensureSiretColumn()) {
            return false;
        }

        return $this->installDefaultConfiguration();
    }

    public function uninstall(): bool
    {
        // On respecte le choix de conservation via la variable POST `snt_ip_keep_data`
        // proposée dans le confirmUninstall si un jour on personnalise le prompt.
        $keepData = (bool) Tools::getValue('snt_ip_keep_data', false);

        if (!$keepData) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_log`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_insee_cache`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_vies_cache`');
        }

        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('SNT_IP_LOG_LAST_PURGE_TS');

        return parent::uninstall();
    }

    private function installTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro` (
            `id_snt_inscription_pro` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer`            INT(11) UNSIGNED NOT NULL,
            `vatNumber`              VARCHAR(20)  DEFAULT NULL,
            `afe`                    VARCHAR(34)  DEFAULT NULL,
            `accounting_email`       VARCHAR(255) DEFAULT NULL,
            `einvoicing_address`     VARCHAR(255) DEFAULT NULL,
            `is_administration`      TINYINT(1)   NOT NULL DEFAULT 0,
            `locked_address_id`      INT(11) UNSIGNED DEFAULT NULL,
            `needs_review`           TINYINT(1)   NOT NULL DEFAULT 0,
            `date_add`               DATETIME     NOT NULL,
            `date_upd`               DATETIME     NOT NULL,
            PRIMARY KEY (`id_snt_inscription_pro`),
            UNIQUE KEY `uniq_id_customer` (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function installLogTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_log` (
            `id_log`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `type`        VARCHAR(32)  NOT NULL,
            `severity`    TINYINT(1)   NOT NULL DEFAULT 1,
            `id_customer` INT(11) UNSIGNED DEFAULT NULL,
            `siret`       VARCHAR(14)  DEFAULT NULL,
            `ip`          VARCHAR(45)  DEFAULT NULL,
            `message`     VARCHAR(255) DEFAULT NULL,
            `context`     TEXT         DEFAULT NULL,
            `date_add`    DATETIME     NOT NULL,
            PRIMARY KEY (`id_log`),
            KEY `idx_type_date` (`type`, `date_add`),
            KEY `idx_ip_date` (`ip`, `date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Cache serveur des établissements INSEE (clé = SIRET). Sert à éviter le
     * second appel INSEE au submit quand le SIRET a déjà été vu au blur.
     * Cf. SNT\InscriptionPro\Repository\InseeCacheRepository.
     */
    private function installCacheTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_insee_cache` (
            `siret`    VARCHAR(14)  NOT NULL,
            `company`  VARCHAR(255) DEFAULT NULL,
            `active`   TINYINT(1)   NOT NULL DEFAULT 0,
            `closed`   TINYINT(1)   NOT NULL DEFAULT 0,
            `address1` VARCHAR(255) DEFAULT NULL,
            `address2` VARCHAR(255) DEFAULT NULL,
            `postcode` VARCHAR(16)  DEFAULT NULL,
            `city`     VARCHAR(255) DEFAULT NULL,
            `date_add` DATETIME     NOT NULL,
            PRIMARY KEY (`siret`),
            KEY `idx_date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Cache serveur des vérifications VIES (clé = n° TVA complet). Évite de
     * re-solliciter VIES (lent + rate-limité) pour un numéro déjà vérifié.
     * Cf. SNT\InscriptionPro\Repository\ViesCacheRepository.
     */
    private function installViesCacheTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro_vies_cache` (
            `vat_number` VARCHAR(20)  NOT NULL,
            `valid`      TINYINT(1)   NOT NULL DEFAULT 0,
            `name`       VARCHAR(255) DEFAULT NULL,
            `address`    VARCHAR(512) DEFAULT NULL,
            `date_add`   DATETIME     NOT NULL,
            PRIMARY KEY (`vat_number`),
            KEY `idx_date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function ensureSiretColumn(): bool
    {
        $rows = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'customer` LIKE "siret"'
        );
        if (is_array($rows) && count($rows) > 0) {
            return true;
        }
        return (bool) Db::getInstance()->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'customer` ADD COLUMN `siret` VARCHAR(14) DEFAULT NULL'
        );
    }

    private function installDefaultConfiguration(): bool
    {
        $defaults = self::CONFIG_KEYS;
        if ($defaults['SNT_IP_API_KEY'] === '') {
            $defaults['SNT_IP_API_KEY'] = $this->generateApiKey();
        }
        foreach ($defaults as $key => $value) {
            if (Configuration::hasKey($key)) {
                continue;
            }
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private function generateApiKey(): string
    {
        return bin2hex(random_bytes(24));
    }

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    /**
     * Injecte les champs pro dans le formulaire natif d'inscription/édition.
     * Si `siret` ou `company` sont déjà déclarés (mode B2B activé, ou autre
     * module), on ne les redéclare pas — on met juste à jour leur valeur et
     * leur required — pour éviter les doublons dans le DOM et une double
     * validation PS.
     *
     * @param array{fields: FormField[]} $params
     * @return FormField[]
     */
    public function hookAdditionalCustomerFormFields($params): array
    {
        $existingByName = [];
        foreach (($params['fields'] ?? []) as $field) {
            if ($field instanceof FormField) {
                $existingByName[$field->getName()] = $field;
            }
        }

        $customer = $this->context->customer;
        $prefill  = $this->prefillFromCustomer($customer);

        $additions = [];

        // Requis dynamique des champs pro. Un champ pro masqué (compte
        // particulier, ou branche non-FR) ne doit JAMAIS porter un `required`
        // (HTML5 ou serveur PS) : il bloquerait la soumission d'un pro non
        // concerné. On calcule donc le contexte effectif :
        //  - à la soumission (POST) : d'après is_pro / pro_country réellement
        //    postés, pour que le cœur PS valide le required dans la bonne
        //    branche uniquement ;
        //  - au rendu initial (GET) : on suppose le parcours pro afin que les
        //    labels s'affichent « obligatoire » dès que la branche pro est
        //    montrée (le JS retire le required des champs restés masqués).
        $submitted  = Tools::getIsset('is_pro');
        $isProVal   = $submitted ? (string) Tools::getValue('is_pro') : '';
        // « Professionnel » ('1') et « Administration » ('2') sont deux comptes pro :
        // même parcours de champs. Seule l'AFE distingue (masquée pour l'administration).
        $isProEff   = $submitted ? ($isProVal === '1' || $isProVal === '2') : true;
        $countryEff = strtoupper(trim((string) ($submitted
            ? Tools::getValue('pro_country', 'FR')
            : $prefill['pro_country'])));
        if ($countryEff === '') {
            $countryEff = 'FR';
        }
        // `$reqFr` : champs propres à la branche FR (SIREN, adresse de
        // facturation électronique). `$isProEff` : champs présents dans les deux
        // branches (raison sociale).
        $reqFr = $isProEff && ($countryEff === 'FR');

        // Administration (3ᵉ choix du « Type de compte », is_pro = '2') -> pas
        // d'adresse de facturation électronique exigée. À la soumission on lit la
        // valeur postée du radio, au rendu la valeur stockée (édition). Sert à
        // aligner le required cœur PS pour ne pas bloquer une administration à AFE
        // vide ; l'obligation est aussi vérifiée dans validateFrenchPro.
        $isAdminEff  = $submitted ? ($isProVal === '2') : (bool) $prefill['is_administration'];
        $reqEinvoice = $reqFr && !$isAdminEff;

        // is_pro : toujours ajouté (n'est jamais natif). NON requis côté serveur :
        // la valeur « Particulier » vaut '0', or la validation required du cœur PS
        // traite '0' comme vide (test de type empty()) et lèverait « Champ requis »
        // sur un particulier. Le radio a toujours une valeur (Particulier
        // pré-sélectionné via setValue), donc l'obligation serveur est inutile.
        // Inscription des particuliers désactivée (SNT_IP_ALLOW_INDIVIDUAL) : le
        // choix « Particulier » est retiré et « Professionnel » pré-sélectionné.
        $typeChoices = [
            '0' => $this->l('Particulier'),
            '1' => $this->l('Professionnel'),
            // Administration : compte pro identique au professionnel, mais hors
            // périmètre de la facturation électronique (AFE non demandée, cf. JS
            // et validateFrenchPro). Persisté via la colonne `is_administration`.
            '2' => $this->l('Administration'),
        ];
        $typeValue = $prefill['is_pro'];
        if (!$this->isIndividualSignupAllowed()) {
            unset($typeChoices['0']);
            if ($typeValue === '0') {
                $typeValue = '1';
            }
        }
        $additions[] = (new FormField())
            ->setName('is_pro')
            ->setType('radio-buttons')
            ->setLabel($this->l('Type de compte'))
            ->setValue($typeValue)
            ->setAvailableValues($typeChoices)
            ->setRequired(false);

        // pro_country : pays de l'entreprise. Aiguille le parcours :
        //  - FR  -> SIREN / INSEE (+ VIES en complément) ;
        //  - autre UE -> n° TVA seul, vérifié par VIES.
        // Le JS masque/affiche les sous-champs selon ce choix ; le serveur route
        // la validation dans hookValidateCustomerFormFields.
        // Requis pour un pro uniquement (`$isProEff`) : masqué pour un
        // particulier, il ne doit pas participer à la validation required.
        $additions[] = (new FormField())
            ->setName('pro_country')
            ->setType('select')
            ->setLabel($this->l('Pays de l\'entreprise'))
            ->setValue($prefill['pro_country'])
            ->setAvailableValues($this->euCountryChoices())
            ->setRequired($isProEff);

        // siret : point d'entrée du parcours FR. Le client le saisit (14 chiffres) ;
        // au blur, le JS interroge l'INSEE et affiche un aperçu (raison sociale +
        // adresse). C'est la valeur autoritative re-vérifiée au submit (INSEE),
        // qui alimente aussi l'adresse siège créée d'office. Obligatoire pour un
        // pro FR (`$reqFr`) ; NON requis en non-FR (masqué) — sinon un `required`
        // HTML5 sur un champ caché bloque la soumission. On réutilise le field
        // natif s'il existe (mode B2B PS), sinon on l'ajoute.
        if (isset($existingByName['siret'])) {
            if ($prefill['siret'] !== '') {
                $existingByName['siret']->setValue($prefill['siret']);
            }
            $existingByName['siret']->setRequired($reqFr);
        } else {
            $additions[] = (new FormField())
                ->setName('siret')
                ->setType('text')
                ->setLabel($this->l('SIRET'))
                ->setValue($prefill['siret'])
                ->setRequired($reqFr);
        }

        // company : idem. Le readonly côté client est géré par le JS via
        // SNT_IP_COMPANY_EDITABLE ; le serveur verrouille dans validate.
        // Obligatoire pour tout pro (`$isProEff`), dans les deux branches :
        //  - FR       : renseignée automatiquement par l'INSEE (ou saisie
        //               manuelle dans le fallback INSEE indisponible) ;
        //  - autre UE : renseignée par VIES ou saisie manuellement.
        if (isset($existingByName['company'])) {
            if ($prefill['company'] !== '') {
                $existingByName['company']->setValue($prefill['company']);
            }
            $existingByName['company']->setRequired($isProEff);
        } else {
            $additions[] = (new FormField())
                ->setName('company')
                ->setType('text')
                ->setLabel($this->l('Raison sociale'))
                ->setValue($prefill['company'])
                ->setRequired($isProEff);
        }

        // vatNumber : PS peut aussi le fournir en mode B2B — même stratégie.
        if (isset($existingByName['vatNumber'])) {
            if ($prefill['vatNumber'] !== '') {
                $existingByName['vatNumber']->setValue($prefill['vatNumber']);
            }
        } else {
            $additions[] = (new FormField())
                ->setName('vatNumber')
                ->setType('text')
                ->setLabel($this->l('N° TVA intracom.'))
                ->setValue($prefill['vatNumber']);
        }

        // afe : plus demandée au moment de la création/édition du compte. Elle
        // est désormais rattachée à l'adresse de facturation (cf. formulaire
        // d'adresse). La colonne DB / l'endpoint / le BO restent inchangés pour
        // ne pas perdre les données déjà saisies.

        // téléphone : obligatoire pour créer l'adresse « siège social » (une
        // adresse PS doit porter un téléphone). Uniquement à la CRÉATION du
        // compte — à l'édition, l'adresse existe déjà (et sera verrouillée).
        // Non persisté en table module : consommé une seule fois pour l'adresse.
        // Requis suivant `$reqFr` (comme le SIRET) : obligatoire en branche FR pro
        // (supprime aussi la mention « (optionnel) » du libellé), non requis en
        // non-FR / particulier — le JS retire le `required` HTML5 sur le champ
        // masqué pour ne pas bloquer la soumission.
        if (!$this->isEditingExistingCustomer()) {
            $additions[] = (new FormField())
                ->setName('phone')
                ->setType('text')
                ->setLabel($this->l('Téléphone'))
                ->setValue('')
                ->setRequired($reqFr);
        }

        // email comptable : demandé par le service comptabilité. Persisté en
        // table module (NULL si vide) et exposé à l'ERP via l'endpoint API.
        // Facultatif : jamais requis. Le format est vérifié côté serveur
        // uniquement s'il est renseigné (cf. hookValidateCustomerFormFields).
        $additions[] = (new FormField())
            ->setName('accounting_email')
            ->setType('text')
            ->setLabel($this->l('Email comptable'))
            ->setValue($prefill['accounting_email'])
            ->setRequired(false);

        // Administration : plus de case dédiée — c'est désormais le 3ᵉ choix du
        // « Type de compte » (is_pro = '2', cf. plus haut). Le caractère
        // « administration » est dérivé de cette valeur, persisté dans la colonne
        // `is_administration` et exposé à l'ERP via l'endpoint API.

        // Adresse de facturation électronique (réforme de la facturation
        // électronique) : identifiant de routage, = le SIREN dans la grande
        // majorité des cas (bouton « Utiliser mon SIREN » côté JS), mais un autre
        // identifiant reste possible. Persistée en table module et exposée à
        // l'ERP via l'endpoint API. Obligatoire pour un pro FR (`$reqEinvoice`),
        // SAUF administration (case cochée) ; masqué et non requis en non-FR
        // (réforme française uniquement).
        $additions[] = (new FormField())
            ->setName('einvoicing_address')
            ->setType('text')
            ->setLabel($this->l('Adresse de facturation électronique'))
            ->setValue($prefill['einvoicing_address'])
            ->setRequired($reqEinvoice);

        return $additions;
    }

    /**
     * Validation serveur : routage selon le pays de l'entreprise.
     *  - FR       -> SIREN/SIRET + re-vérif INSEE + VIES en complément ;
     *  - autre UE -> n° TVA seul, vérifié par VIES (bloquant si invalide).
     * L'email comptable est requis dans les deux branches.
     * @param array{fields: FormField[]} $params
     */
    public function hookValidateCustomerFormFields($params): array
    {
        $fields = $params['fields'] ?? [];
        $errors = [];

        // Remis à zéro pour cette requête : positionnés par applyInseeCheck /
        // applyViesCheck.
        $this->pendingNeedsReview  = false;
        $this->pendingInseeAddress = [];

        // Professionnel ('1') ou Administration ('2') : même validation pro.
        // Particulier ('0') : rien à valider, sauf si l'inscription des
        // particuliers est désactivée — on refuse alors côté serveur (le choix
        // est retiré du formulaire, mais une requête forgée pourrait le poster).
        $isProValue = (string) $this->extractFieldValue($fields, 'is_pro');
        if ($isProValue !== '1' && $isProValue !== '2') {
            if (!$this->isIndividualSignupAllowed()) {
                foreach ($fields as $field) {
                    if ($field->getName() === 'is_pro') {
                        $field->addError($this->l('L\'inscription est réservée aux professionnels et aux administrations.'));
                    }
                }
            }
            return $errors;
        }

        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->getName()] = $field;
        }

        // Email comptable : facultatif. On vérifie seulement le format s'il est
        // renseigné ; un champ vide est accepté (stocké NULL) dans les deux pays.
        if (isset($byName['accounting_email'])) {
            $accEmail = trim((string) $byName['accounting_email']->getValue());
            if ($accEmail !== '' && !Validate::isEmail($accEmail)) {
                $byName['accounting_email']->addError($this->l('Email comptable invalide.'));
            }
        }

        $country = strtoupper(trim((string) ($this->extractFieldValue($fields, 'pro_country') ?? 'FR')));
        if ($country === '') {
            $country = 'FR';
        }

        if ($country === 'FR') {
            $this->validateFrenchPro($byName);
        } else {
            $this->validateForeignPro($byName, $country);
        }

        return $errors;
    }

    /**
     * Branche FR : SIREN/SIRET (Luhn) + re-vérif INSEE (autoritative pour company
     * et adresse siège) + VIES en complément (non bloquant) sur la TVA saisie.
     *
     * @param array<string,FormField> $byName
     */
    private function validateFrenchPro(array $byName): void
    {
        // SIRET : point d'entrée obligatoire du parcours FR (le SIREN n'est plus
        // demandé). Vide -> erreur ; renseigné -> contrôle de format (14 chiffres
        // + clé Luhn) avant la re-vérif INSEE (applyInseeCheck).
        $siretField = $byName['siret'] ?? null;
        if ($siretField) {
            $siret = SiretValidator::normalize((string) $siretField->getValue());
            if ($siret === '') {
                $siretField->addError($this->l('Le SIRET est obligatoire pour un compte professionnel.'));
            } elseif (!SiretValidator::isSiret($siret)) {
                $siretField->addError($this->l('SIRET invalide : 14 chiffres attendus et clé de contrôle Luhn incorrecte.'));
            }
        }

        if (isset($byName['phone'])) {
            $phone = trim((string) $byName['phone']->getValue());
            if ($phone === '') {
                $byName['phone']->addError($this->l('Le téléphone est obligatoire pour un compte professionnel.'));
            } elseif (!Validate::isPhoneNumber($phone)) {
                $byName['phone']->addError($this->l('Numéro de téléphone invalide.'));
            }
        }

        // Adresse de facturation électronique : obligatoire pour un pro FR, SAUF
        // si le client a coché « Je suis une administration » (les administrations
        // sont hors périmètre de la facturation électronique). Règle de format :
        // si la valeur ressemble à un SIRET (14 chiffres) on vérifie la clé Luhn ;
        // un SIREN (9 chiffres) ou tout autre identifiant non vide est accepté
        // (255 caractères max). Le type de compte « Administration » (is_pro = '2')
        // est la source de vérité (déclaratif).
        $isAdministration = ((string) Tools::getValue('is_pro') === '2');
        if (isset($byName['einvoicing_address'])) {
            $einv = trim((string) $byName['einvoicing_address']->getValue());
            if ($einv === '') {
                if (!$isAdministration) {
                    $byName['einvoicing_address']->addError($this->l('L\'adresse de facturation électronique est obligatoire pour un compte professionnel.'));
                }
            } elseif (mb_strlen($einv) > 255) {
                $byName['einvoicing_address']->addError($this->l('Adresse de facturation électronique trop longue (255 caractères maximum).'));
            } else {
                $einvDigits = preg_replace('/\s+/', '', $einv);
                if (preg_match('/^\d{14}$/', (string) $einvDigits) && !SiretValidator::isSiret(SiretValidator::normalize($einv))) {
                    $byName['einvoicing_address']->addError($this->l('Adresse de facturation électronique : ce SIRET est invalide (clé de contrôle Luhn incorrecte).'));
                }
            }
        }

        // N° TVA intracom. : contrôle de format seulement (facultatif en FR).
        $vatField = $byName['vatNumber'] ?? null;
        if ($vatField) {
            $vat = VatValidator::normalize((string) $vatField->getValue());
            if ($vat !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat)) {
                $vatField->addError($this->l('N° TVA intracommunautaire au format invalide.'));
            }
        }

        // INSEE (company + adresse) puis VIES en complément (non bloquant en FR).
        $this->applyInseeCheck($siretField, $byName['company'] ?? null, $vatField);
        $this->applyViesCheck($vatField, $byName['company'] ?? null, 'FR', true);
    }

    /**
     * Branche non-FR : la TVA est l'identifiant. Format (par pays) + VIES
     * (bloquant si invalide). La raison sociale vient de VIES si disponible,
     * sinon saisie manuelle obligatoire.
     *
     * @param array<string,FormField> $byName
     */
    private function validateForeignPro(array $byName, string $isoCountry): void
    {
        $vatField     = $byName['vatNumber'] ?? null;
        $companyField = $byName['company'] ?? null;

        if (!$vatField) {
            return;
        }

        $raw = VatValidator::normalize((string) $vatField->getValue());
        if ($raw === '') {
            $vatField->addError($this->l('Le numéro de TVA intracommunautaire est obligatoire.'));
            return;
        }

        [$cc, $num] = VatValidator::split($raw);
        if (!VatValidator::isSupportedCountry($cc)) {
            // Pas de préfixe pays reconnu : on préfixe avec le pays choisi.
            $cc  = VatValidator::isoToViesCountry($isoCountry);
            $num = $raw;
        }
        if (!VatValidator::isValidFormat($cc . $num)) {
            $vatField->addError($this->l('Numéro de TVA au format invalide pour ce pays.'));
            return;
        }

        // VIES (bloquant si invalide ; dégradation gracieuse si indisponible).
        $this->applyViesCheck($vatField, $companyField, $isoCountry, false);

        // Raison sociale : si VIES ne l'a pas fournie, saisie manuelle requise.
        if ($companyField && trim((string) $companyField->getValue()) === '') {
            $companyField->addError($this->l('La raison sociale est obligatoire (non fournie par VIES pour ce pays).'));
        }
    }

    /**
     * Vérification VIES d'un n° de TVA intracom. + décision :
     *  - VALID     : (non-FR) raison sociale autoritative depuis VIES ;
     *  - INVALID   : bloquant en non-FR, signalé (needs_review) en FR ;
     *  - INDISPO   : strict -> bloquant ; sinon accepté + needs_review.
     *
     * Format -> cache -> appel VIES. Seuls les appels réellement émis sont
     * journalisés (`vies_call`). Ne fait jamais confiance au blur.
     */
    private function applyViesCheck(?FormField $vatField, ?FormField $companyField, string $isoCountry, bool $isFr): void
    {
        if (!$vatField || !(bool) Configuration::get('SNT_IP_VIES_ENABLE')) {
            return;
        }
        $raw = VatValidator::normalize((string) $vatField->getValue());
        if ($raw === '') {
            return; // FR : TVA optionnelle ; non-FR : l'obligation est gérée en amont
        }

        [$cc, $num] = VatValidator::split($raw);
        if (!VatValidator::isSupportedCountry($cc)) {
            $cc  = VatValidator::isoToViesCountry($isoCountry);
            $num = $raw;
        }
        $full = $cc . $num;

        if (!VatValidator::isValidFormat($full)) {
            if (!$isFr) {
                $vatField->addError($this->l('Numéro de TVA au format invalide.'));
            }
            return;
        }

        $strict = (bool) Configuration::get('SNT_IP_VIES_STRICT');

        // Cache VIES (économie de quota + latence). Réutilise le résultat émis au
        // blur ; sinon appel réel + mise en cache.
        $cache  = new ViesCacheRepository();
        $ttl    = (int) Configuration::get('SNT_IP_VIES_CACHE_TTL');
        $cached = $cache->get($full, $ttl > 0 ? $ttl : 604800);

        if ($cached !== null) {
            $result = $cached['valid']
                ? ViesResult::valid(['countryCode' => $cc, 'vatNumber' => $num, 'name' => $cached['name'], 'address' => $cached['address']])
                : ViesResult::invalid($cc, $num);
        } else {
            $this->getLogger()->viesCall(Tools::getRemoteAddr(), $full);
            $result = (new ViesClient())->checkVat($cc, $num);
            if ($result->status === ViesResult::STATUS_VALID) {
                $cache->put($full, true, $result->name, $result->address);
            } elseif ($result->status === ViesResult::STATUS_INVALID) {
                $cache->put($full, false, null, null);
            } else {
                $this->getLogger()->viesError($result->reason ?: $result->status, $full, Tools::getRemoteAddr());
            }
        }

        switch ($result->status) {
            case ViesResult::STATUS_VALID:
                // Raison sociale autoritative depuis VIES (non-FR uniquement, et
                // seulement si VIES la fournit — DE/ES la renvoient souvent vide).
                if (!$isFr && $companyField && $result->hasName()) {
                    $companyField->setValue((string) $result->name);
                }
                break;

            case ViesResult::STATUS_INVALID:
                if ($isFr) {
                    // Entreprise réelle (INSEE) mais non assujettie intracom. :
                    // on signale sans bloquer.
                    $this->pendingNeedsReview = true;
                } else {
                    $vatField->addError($this->l('Ce numéro de TVA n\'est pas valide dans VIES.'));
                }
                break;

            case ViesResult::STATUS_BAD_REQUEST:
                if (!$isFr) {
                    $vatField->addError($this->l('Numéro de TVA invalide.'));
                }
                break;

            case ViesResult::STATUS_UNAVAILABLE:
            case ViesResult::STATUS_RATE_LIMITED:
            default:
                if ($strict) {
                    $vatField->addError($this->l('Service VIES momentanément indisponible, merci de réessayer.'));
                } else {
                    // Dégradation gracieuse : accepté, à vérifier.
                    $this->pendingNeedsReview = true;
                }
                break;
        }
    }

    /**
     * Ré-appelle l'INSEE côté serveur pour ancrer company (autoritatif) et
     * pré-remplir vatNumber. Gère les modes strict / dégradation gracieuse
     * (`SNT_IP_INSEE_STRICT`).
     *
     * En mode non-strict, si l'INSEE ne peut pas confirmer le SIRET (introuvable
     * ou indisponible), le compte est accepté avec la raison sociale SAISIE
     * MANUELLEMENT (fallback), marqué « à vérifier » (`pendingNeedsReview`), et
     * une alerte e-mail throttlée est envoyée au support pour les incidents
     * d'infrastructure.
     */
    private function applyInseeCheck(?FormField $siretField, ?FormField $companyField, ?FormField $vatField): void
    {
        if (!$siretField) {
            return;
        }
        $siret = SiretValidator::normalize((string) $siretField->getValue());
        if ($siret === '' || !SiretValidator::isSiret($siret)) {
            return; // erreurs de format déjà remontées le cas échéant
        }

        // Le n° de TVA n'est PLUS auto-calculé depuis le SIREN : le calcul mod-97
        // peut produire un numéro erroné (constaté en production). La valeur
        // saisie par le client est conservée telle quelle ; sa validité sera
        // confirmée par VIES (cf. vérification TVA intracom., Step 2).
        $strict = (bool) Configuration::get('SNT_IP_INSEE_STRICT');

        // Économie de quota INSEE : le blur (aperçu SIRET) a déjà mémorisé
        // l'établissement en cache serveur. Si le SIRET soumis y figure encore
        // (dans le TTL), on réutilise cette donnée — émise côté serveur, donc
        // autoritative — au lieu de rappeler l'INSEE. Sinon (cache expiré, édition
        // sans blur), on interroge l'INSEE et on met le cache à jour. Seuls les
        // appels réellement émis sont journalisés (`insee_call`), pour garder le
        // compteur du journal exact.
        $cache = new InseeCacheRepository();
        $ttl   = (int) Configuration::get('SNT_IP_INSEE_CACHE_TTL');
        $cached = $cache->get($siret, $ttl > 0 ? $ttl : 86400);

        if ($cached !== null) {
            $result = InseeResult::found($cached);
        } else {
            $this->getLogger()->inseeCall(Tools::getRemoteAddr(), $siret);
            $result = (new InseeClient())->fetchSiret($siret);
            if ($result->status === InseeResult::STATUS_FOUND) {
                $cache->put($siret, [
                    'company'  => $result->company,
                    'active'   => $result->active,
                    'closed'   => $result->closed,
                    'address1' => $result->address1,
                    'address2' => $result->address2,
                    'postcode' => $result->postcode,
                    'city'     => $result->city,
                ]);
            }
        }

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                if ($companyField && (bool) Configuration::get('SNT_IP_WRITE_COMPANY') && $result->company) {
                    // Source autoritative : on écrase toute saisie cliente.
                    $companyField->setValue($result->company);
                } elseif ($companyField && !(bool) Configuration::get('SNT_IP_COMPANY_EDITABLE')) {
                    // Pas d'écriture INSEE mais édition libre interdite : on
                    // verrouille sur la valeur en base pour empêcher la fraude.
                    $companyField->setValue($this->existingCompany());
                }
                // Adresse siège pour création d'office (consommée à l'ajout du
                // compte). On n'exige que les champs postaux minimaux de PS.
                if ($result->hasUsableAddress()) {
                    $this->pendingInseeAddress = [
                        'address1' => (string) $result->address1,
                        'address2' => (string) ($result->address2 ?? ''),
                        'postcode' => (string) $result->postcode,
                        'city'     => (string) $result->city,
                        'siren'    => $result->siren,
                    ];
                }
                break;

            case InseeResult::STATUS_NOT_FOUND:
                if ($strict) {
                    $siretField->addError($this->l('SIRET introuvable dans le répertoire INSEE.'));
                    break;
                }
                // Non-strict : SIRET valide localement mais inconnu INSEE →
                // fallback saisie manuelle, compte à vérifier (pas d'alerte : ce
                // n'est pas un incident d'infrastructure).
                $this->pendingNeedsReview = true;
                break;

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
            case InseeResult::STATUS_RATE_LIMITED:
            case InseeResult::STATUS_UNAVAILABLE:
            default:
                $incident = $result->reason ?: $result->status;
                if ($strict) {
                    $siretField->addError($this->l('Service INSEE momentanément indisponible, merci de réessayer.'));
                    break;
                }
                // Non-strict : on accepte le SIRET (localement valide) avec la
                // raison sociale saisie manuellement, et on trace/alerte.
                $this->pendingNeedsReview = true;
                $this->getLogger()->inseeError((string) $incident, $siret, Tools::getRemoteAddr());
                $this->getMailAlerter()->notifyInseeDown((string) $incident, ['siret' => $siret]);
                break;
        }
    }

    /**
     * Raison sociale actuellement en base pour le client en contexte (ou vide
     * en création). Sert au verrouillage anti-fraude de `company`.
     */
    private function existingCompany(): string
    {
        return ($this->context->customer && (int) $this->context->customer->id > 0)
            ? (string) $this->context->customer->company
            : '';
    }

    /**
     * Persistance à la création.
     * @param array{newCustomer: Customer} $params
     */
    public function hookActionCustomerAccountAdd($params): void
    {
        $customer = $params['newCustomer'] ?? null;
        if ($customer instanceof Customer) {
            $this->persistProData($customer);
            // Création d'office de l'adresse siège depuis l'INSEE (uniquement à
            // la création du compte, pas à l'édition).
            $this->maybeCreateInseeAddress($customer);
        }
    }

    /**
     * Persistance à l'édition.
     * @param array{customer: Customer} $params
     */
    public function hookActionCustomerAccountUpdate($params): void
    {
        $customer = $params['customer'] ?? null;
        if ($customer instanceof Customer) {
            $this->persistProData($customer);
        }
    }

    /**
     * Purge en cascade à la suppression du client.
     * @param array{object: Customer} $params
     */
    public function hookActionObjectCustomerDeleteAfter($params): void
    {
        $customer = $params['object'] ?? null;
        if ($customer instanceof Customer && (int) $customer->id > 0) {
            $this->getRepository()->deleteByCustomer((int) $customer->id);
        }
    }

    /**
     * Verrouillage en édition de l'adresse « siège social » créée d'office :
     * PrestaShop ne sait pas rendre une adresse non éditable côté thème sans
     * override, on l'impose donc côté serveur. On restaure silencieusement les
     * valeurs persistées avant l'écriture → toute modification cliente devient
     * un no-op (non destructif, pas d'erreur affichée).
     *
     * @param array{object: Address} $params
     */
    public function hookActionObjectAddressUpdateBefore($params): void
    {
        $address = $params['object'] ?? null;
        if (!$address instanceof Address || (int) $address->id <= 0) {
            return;
        }
        if (!$this->getRepository()->isLockedAddress((int) $address->id)) {
            return;
        }

        $original = new Address((int) $address->id);
        if ((int) $original->id <= 0) {
            return;
        }
        foreach ([
            'id_country', 'id_state', 'alias', 'company', 'lastname', 'firstname',
            'vat_number', 'address1', 'address2', 'postcode', 'city', 'other',
            'phone', 'phone_mobile', 'dni',
        ] as $prop) {
            $address->$prop = $original->$prop;
        }

        $this->getLogger()->log(Logger::TYPE_ADDRESS_LOCKED, [
            'severity'    => Logger::SEVERITY_INFO,
            'id_customer' => (int) $original->id_customer,
            'message'     => 'Édition d\'une adresse verrouillée ignorée',
            'context'     => ['id_address' => (int) $address->id],
        ]);
    }

    /**
     * Verrouillage en suppression de l'adresse « siège social » : refus par
     * exception contrôlée tant que l'adresse est marquée verrouillée.
     *
     * @param array{object: Address} $params
     */
    public function hookActionObjectAddressDeleteBefore($params): void
    {
        $address = $params['object'] ?? null;
        if (!$address instanceof Address || (int) $address->id <= 0) {
            return;
        }
        if (!$this->getRepository()->isLockedAddress((int) $address->id)) {
            return;
        }

        $this->getLogger()->log(Logger::TYPE_ADDRESS_LOCKED, [
            'severity'    => Logger::SEVERITY_WARNING,
            'id_customer' => (int) $address->id_customer,
            'message'     => 'Suppression d\'une adresse verrouillée refusée',
            'context'     => ['id_address' => (int) $address->id],
        ]);

        throw new PrestaShopException(
            $this->l('Cette adresse (siège social) est verrouillée et ne peut pas être supprimée.')
        );
    }

    /**
     * Chargement CSS/JS strictement sur les pages exposant le formulaire client.
     * PS 8 utilise `registration` (création), `authentication` (login),
     * `identity` (édition compte) et `order` (inscription au checkout).
     */
    public function hookActionFrontControllerSetMedia($params): void
    {
        $selfName = $this->context->controller->php_self ?? null;

        // Bandeau d'invitation pro : styles chargés sur les pages où il peut
        // apparaître (panier + tunnel). registration.css ne couvre pas le panier
        // (`cart`), d'où ce fichier dédié.
        if (in_array($selfName, ['cart', 'order'], true)) {
            $this->context->controller->registerStylesheet(
                'snt-ip-pro-invite',
                'modules/' . $this->name . '/views/css/pro-invite.css',
                ['media' => 'all', 'priority' => 200]
            );
        }

        if (!in_array($selfName, ['registration', 'authentication', 'identity', 'order'], true)) {
            return;
        }

        Media::addJsDef([
            'SNT_IP_VALIDATE_URL' => $this->context->link->getModuleLink(
                $this->name, 'validate', [], true
            ),
            'SNT_IP_COMPANY_EDITABLE' => (bool) Configuration::get('SNT_IP_COMPANY_EDITABLE'),
            'SNT_IP_AFE_REGEX'        => (string) Configuration::get('SNT_IP_AFE_REGEX'),
            'SNT_IP_SIRET_REQUIRED'   => (bool) Configuration::get('SNT_IP_SIRET_REQUIRED'),
            'SNT_IP_VIES_ENABLE'      => (bool) Configuration::get('SNT_IP_VIES_ENABLE'),
        ]);

        $this->context->controller->registerStylesheet(
            'snt-ip-registration',
            'modules/' . $this->name . '/views/css/registration.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'snt-ip-registration',
            'modules/' . $this->name . '/views/js/registration.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }

    // ------------------------------------------------------------------
    // Invitation « mettez à jour vos informations pro » (clients pré-existants)
    // ------------------------------------------------------------------

    /**
     * Panier : bandeau d'invitation sous le récapitulatif du panier.
     * @param array<string,mixed> $params
     */
    public function hookDisplayShoppingCartFooter($params): string
    {
        return $this->renderProInvite();
    }

    /**
     * Tunnel de commande : bandeau d'invitation en haut du récapitulatif.
     * @param array<string,mixed> $params
     */
    public function hookDisplayCheckoutSummaryTop($params): string
    {
        return $this->renderProInvite();
    }

    /**
     * Rendu du bandeau invitant les clients pro « historiques » à compléter leurs
     * informations pour bénéficier de la facturation électronique.
     *
     * Déclencheur : le client connecté appartient au groupe « pro à compléter »
     * (`SNT_IP_INVITE_GROUP_ID`, alimenté par la migration SQL des clients qui
     * avaient une raison sociale) ET n'a aucune ligne dans `ps_snt_inscription_pro`
     * (il n'est pas encore passé par le parcours pro du module). Chaîne vide sinon.
     */
    private function renderProInvite(): string
    {
        $customer = $this->context->customer;
        if (!$customer instanceof Customer || !$customer->isLogged() || (int) $customer->id <= 0) {
            return '';
        }

        // Groupe « pro à compléter » : si non configuré, le bandeau est désactivé.
        $inviteGroup = (int) Configuration::get('SNT_IP_INVITE_GROUP_ID');
        if ($inviteGroup <= 0) {
            return '';
        }

        // Le client doit appartenir au groupe.
        $groups = array_map('intval', (array) $customer->getGroups());
        if (!in_array($inviteGroup, $groups, true)) {
            return '';
        }

        // Déjà connu du module : rien à proposer.
        if ($this->getRepository()->findByCustomer((int) $customer->id) !== null) {
            return '';
        }

        $this->smarty->assign([
            'snt_ip_identity_url' => $this->context->link->getPageLink('identity', true),
            'snt_ip_dismiss_url'  => $this->context->link->getModuleLink($this->name, 'dismiss', [], true),
            // Token anti-CSRF — MÊME formule que le contrôleur dismiss
            // (Snt_inscription_proDismissModuleFrontController::dismissToken).
            'snt_ip_dismiss_token' => substr(Tools::hash('sntip-dismiss-' . (int) $customer->id), 0, 32),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/pro-invite.tpl');
    }

    // ------------------------------------------------------------------
    // Persistance
    // ------------------------------------------------------------------

    private function persistProData(Customer $customer): void
    {
        $idCustomer = (int) $customer->id;
        if ($idCustomer <= 0) {
            return;
        }

        $isPro = Tools::getValue('is_pro', null);
        if ($isPro === null) {
            // Hook déclenché hors formulaire (import, API interne...) : on n'écrase rien.
            return;
        }
        // Professionnel ('1') et Administration ('2') sont tous deux des comptes pro.
        $isPro = ((string) $isPro === '1' || (string) $isPro === '2');

        if (!$isPro) {
            $this->handleDowngrade($customer);
            return;
        }

        $siret     = SiretValidator::normalize((string) Tools::getValue('siret', ''));
        $vatNumber = strtoupper(preg_replace('/\s+/', '', (string) Tools::getValue('vatNumber', '')));

        // On lit une seule fois la ligne existante pour préserver les champs non
        // resoumis (hook déclenché hors formulaire, ou champ retiré du parcours).
        $existing = $this->getRepository()->findByCustomer($idCustomer);

        // L'AFE n'est plus saisie sur le formulaire de compte (elle est rattachée
        // à l'adresse de facturation). Si le champ n'est pas soumis, on préserve
        // la valeur déjà en base plutôt que de l'écraser à vide.
        $afe = Tools::getIsset('afe')
            ? trim((string) Tools::getValue('afe', ''))
            : (string) ($existing['afe'] ?? '');

        // Email comptable : soumis via le formulaire pro ; si absent (hook hors
        // formulaire), on préserve la valeur déjà en base.
        $accountingEmail = Tools::getIsset('accounting_email')
            ? trim((string) Tools::getValue('accounting_email', ''))
            : (string) ($existing['accounting_email'] ?? '');

        // Adresse de facturation électronique : idem, préservée si non resoumise.
        $einvoicingAddress = Tools::getIsset('einvoicing_address')
            ? trim((string) Tools::getValue('einvoicing_address', ''))
            : (string) ($existing['einvoicing_address'] ?? '');

        // Administration : 3ᵉ choix du « Type de compte » (is_pro = '2'). On
        // atteint ce point uniquement quand le formulaire pro a été soumis (cf.
        // garde `is_pro` en amont).
        $isAdministration = ((string) Tools::getValue('is_pro') === '2');

        // Pas d'auto-calcul de la TVA (le calcul mod-97 peut être erroné) : on
        // conserve strictement la valeur saisie par le client (vide => NULL).

        // Écriture du SIRET sur le customer.
        if ($siret !== '' && $customer->siret !== $siret) {
            $customer->siret = $siret;
            $customer->update();
        }

        // Phase 1 : company n'est écrit que si l'admin l'autorise en édition libre.
        // En Phase 2, remplacé par l'écriture depuis la réponse INSEE.
        if ((bool) Configuration::get('SNT_IP_COMPANY_EDITABLE') && (bool) Configuration::get('SNT_IP_WRITE_COMPANY')) {
            $company = trim((string) Tools::getValue('company', ''));
            if ($company !== '' && $customer->company !== $company) {
                $customer->company = $company;
                $customer->update();
            }
        }

        $needsReview = $this->pendingNeedsReview;

        $this->getRepository()->upsert(
            $idCustomer,
            $vatNumber         !== '' ? $vatNumber         : null,
            $afe               !== '' ? $afe               : null,
            $needsReview,
            $accountingEmail   !== '' ? $accountingEmail   : null,
            $einvoicingAddress !== '' ? $einvoicingAddress : null,
            $isAdministration
        );

        // Journalisation : création/mise à jour d'un compte pro.
        if ($needsReview) {
            $this->getLogger()->accountDegraded($idCustomer, $siret !== '' ? $siret : null, 'insee_unconfirmed');
        } else {
            $this->getLogger()->accountCreated($idCustomer, $siret !== '' ? $siret : null);
        }

        // On ne conserve pas l'état dégradé au-delà de la persistance courante.
        $this->pendingNeedsReview = false;
    }

    private function handleDowngrade(Customer $customer): void
    {
        if (!(bool) Configuration::get('SNT_IP_PURGE_ON_DOWNGRADE')) {
            return;
        }
        $customer->siret   = '';
        $customer->company = '';
        $customer->update();
        $this->getRepository()->deleteByCustomer((int) $customer->id);
    }

    /**
     * Crée d'office l'adresse « siège social » du client pro à partir de
     * l'adresse établissement renvoyée par l'INSEE (capturée en validation via
     * `$this->pendingInseeAddress`). No-op si :
     *  - l'INSEE n'a pas confirmé le SIRET ou n'a pas fourni d'adresse exploitable ;
     *  - la France n'est pas résolvable en pays ;
     *  - le client possède déjà au moins une adresse (évite les doublons).
     *
     * Ne fait jamais échouer la création du compte : toute erreur est journalisée
     * et avalée.
     */
    private function maybeCreateInseeAddress(Customer $customer): void
    {
        $data = $this->pendingInseeAddress;
        // On ne conserve pas l'adresse au-delà de la persistance courante.
        $this->pendingInseeAddress = [];

        $idCustomer = (int) $customer->id;
        if ($idCustomer <= 0) {
            return;
        }

        // Pro non-FR (aucun SIRET) : pas d'adresse siège auto (VIES ne fournit pas
        // d'adresse structurée) → saisie manuelle par le client. On sort sans bruit.
        if (empty($data['address1']) && empty($customer->siret)) {
            return;
        }

        // Filet anti-fragilité : `pendingInseeAddress` est porté d'un hook
        // (validation) à l'autre (création) via une propriété d'instance, ce qui
        // suppose que PrestaShop réutilise la même instance de module entre les
        // deux — hypothèse qui n'est PAS garantie. Si l'état a été perdu, on
        // relit l'adresse dans le cache INSEE persistant, par SIRET (source
        // indépendante de la requête). C'est ce cache qui a alimenté le <select>
        // d'établissements, il contient donc l'adresse de l'établissement choisi.
        if (empty($data['address1']) || empty($data['city']) || empty($data['postcode'])) {
            $siret = SiretValidator::normalize((string) $customer->siret);
            if ($siret === '') {
                $siret = SiretValidator::normalize((string) Tools::getValue('siret', ''));
            }
            $ttl    = (int) Configuration::get('SNT_IP_INSEE_CACHE_TTL');
            $cached = $siret !== '' ? (new InseeCacheRepository())->get($siret, $ttl > 0 ? $ttl : 86400) : null;
            if ($cached && !empty($cached['address1']) && !empty($cached['city']) && !empty($cached['postcode'])) {
                $data = [
                    'address1' => (string) $cached['address1'],
                    'address2' => (string) ($cached['address2'] ?? ''),
                    'postcode' => (string) $cached['postcode'],
                    'city'     => (string) $cached['city'],
                    'siren'    => $cached['siren'] ?? SiretValidator::extractSiren($siret),
                ];
            }
        }

        if (empty($data['address1']) || empty($data['city']) || empty($data['postcode'])) {
            // Ni l'état inter-hook ni le cache n'ont fourni d'adresse exploitable.
            $this->getLogger()->log(Logger::TYPE_ADDRESS_LOCKED, [
                'severity'    => Logger::SEVERITY_INFO,
                'id_customer' => $idCustomer,
                'message'     => 'Adresse siège non créée : aucune adresse INSEE disponible (état perdu + cache vide)',
            ]);
            return;
        }

        // Anti-doublon : ne rien créer si le client a déjà une adresse active.
        if ((int) Address::getFirstCustomerAddressId($idCustomer) > 0) {
            $this->getLogger()->log(Logger::TYPE_ADDRESS_LOCKED, [
                'severity'    => Logger::SEVERITY_INFO,
                'id_customer' => $idCustomer,
                'message'     => 'Adresse siège non créée : le client possède déjà une adresse',
            ]);
            return;
        }

        $idCountry = (int) Country::getByIso('FR');
        if ($idCountry <= 0) {
            $this->getLogger()->log(Logger::TYPE_ADDRESS_LOCKED, [
                'severity'    => Logger::SEVERITY_WARNING,
                'id_customer' => $idCustomer,
                'message'     => 'Adresse siège non créée : pays FR non résolvable (Country::getByIso)',
            ]);
            return;
        }

        try {
            $address              = new Address();
            $address->id_customer = $idCustomer;
            $address->id_country  = $idCountry;
            $address->alias       = $this->l('Siège social');
            $address->firstname   = (string) $customer->firstname;
            $address->lastname    = (string) $customer->lastname;
            $address->company     = (string) $customer->company;
            $address->address1    = (string) $data['address1'];
            $address->address2    = (string) ($data['address2'] ?? '');
            $address->postcode    = (string) $data['postcode'];
            $address->city        = (string) $data['city'];

            // Téléphone : obligatoire pour une adresse PS, saisi sur le formulaire
            // pro à la création. Validé en amont dans hookValidateCustomerFormFields.
            $phone = trim((string) Tools::getValue('phone', ''));
            if ($phone !== '') {
                $address->phone = $phone;
            }

            // N° de TVA sur l'adresse : on reporte UNIQUEMENT la valeur saisie par
            // le client (jamais un numéro calculé — le mod-97 peut être erroné).
            // Vide si non renseignée ; VIES la validera (cf. vérification TVA).
            $vatNumber = strtoupper(preg_replace('/\s+/', '', (string) Tools::getValue('vatNumber', '')));
            if ($vatNumber !== '') {
                $address->vat_number = $vatNumber;
            }

            if (!$address->validateFields(false) || !$address->validateFieldsLang(false)) {
                $this->getLogger()->accountDegraded($idCustomer, null, 'insee_address_invalid');
                return;
            }

            $address->add();

            // Verrouillage : cette adresse « siège social » n'est pas éditable
            // par le client (blocage serveur via les hooks Address). On mémorise
            // son id pour que hookActionObjectAddressUpdate/DeleteBefore la
            // reconnaissent.
            if ((int) $address->id > 0) {
                $this->getRepository()->setLockedAddress($idCustomer, (int) $address->id);
            }
        } catch (\Throwable $e) {
            // Une adresse manquée ne doit jamais casser l'inscription.
            $this->getLogger()->accountDegraded($idCustomer, null, 'insee_address_error');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Vrai si le formulaire est rendu pour un client déjà existant (page
     * `identity` / édition compte), faux à la création (`registration`).
     * Sert à n'injecter le champ téléphone qu'à la création (l'adresse
     * « siège social » n'est créée qu'une fois).
     */
    private function isEditingExistingCustomer(): bool
    {
        return $this->context->customer instanceof Customer
            && (int) $this->context->customer->id > 0;
    }

    /**
     * Le type de compte « Particulier » est-il proposé / accepté ? Oui si
     * l'option SNT_IP_ALLOW_INDIVIDUAL est active, ou si le client édite un
     * compte déjà particulier (on ne le force pas à devenir pro pour modifier
     * ses informations).
     */
    private function isIndividualSignupAllowed(): bool
    {
        if ((bool) Configuration::get('SNT_IP_ALLOW_INDIVIDUAL')) {
            return true;
        }
        return $this->isEditingExistingCustomer()
            && $this->prefillFromCustomer($this->context->customer)['is_pro'] === '0';
    }

    private function getRepository(): ProCustomerRepository
    {
        if ($this->repository === null) {
            $this->repository = new ProCustomerRepository();
        }
        return $this->repository;
    }

    private function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger();
        }
        return $this->logger;
    }

    private function getMailAlerter(): MailAlerter
    {
        if ($this->mailAlerter === null) {
            $this->mailAlerter = new MailAlerter($this->getLogger());
        }
        return $this->mailAlerter;
    }

    /**
     * @param array{is_pro:string, siret:string, company:string, vatNumber:string, afe:string}
     */
    private function prefillFromCustomer(?Customer $customer): array
    {
        $defaults = ['is_pro' => '0', 'pro_country' => 'FR', 'siren' => '', 'siret' => '', 'company' => '', 'vatNumber' => '', 'afe' => '', 'accounting_email' => '', 'einvoicing_address' => '', 'is_administration' => false];
        if (!$customer instanceof Customer || (int) $customer->id <= 0) {
            return $defaults;
        }
        $row = $this->getRepository()->findByCustomer((int) $customer->id);
        $hasPro = !empty($customer->siret) || $row !== null;
        $isAdmin = (bool) ($row['is_administration'] ?? false);
        $siret  = (string) ($customer->siret ?? '');
        $vatNumber = (string) ($row['vatNumber'] ?? '');

        // Pays : FR si SIRET présent ; sinon déduit du préfixe TVA (EL->GR) ; FR par défaut.
        $proCountry = 'FR';
        if ($siret === '' && $vatNumber !== '') {
            $prefix = strtoupper(substr(VatValidator::normalize($vatNumber), 0, 2));
            if ($prefix === 'EL') {
                $prefix = 'GR';
            }
            if ($prefix !== '' && $prefix !== 'FR' && VatValidator::isSupportedCountry(VatValidator::isoToViesCountry($prefix))) {
                $proCountry = $prefix;
            }
        }

        return [
            'is_pro'           => $hasPro ? ($isAdmin ? '2' : '1') : '0',
            'pro_country'      => $proCountry,
            'siren'            => $siret !== '' ? SiretValidator::extractSiren($siret) : '',
            'siret'            => $siret,
            'company'          => (string) ($customer->company ?? ''),
            'vatNumber'        => $vatNumber,
            'afe'              => (string) ($row['afe'] ?? ''),
            'accounting_email' => (string) ($row['accounting_email'] ?? ''),
            'einvoicing_address' => (string) ($row['einvoicing_address'] ?? ''),
            'is_administration' => $isAdmin,
        ];
    }

    /**
     * Choix pays pour le sélecteur pro : UE-27 + Irlande du Nord (XI), libellés
     * localisés. La valeur est le code ISO (FR, BE, DE, GR…) ; la Grèce est
     * mappée EL->GR pour VIES au moment de la vérification. FR en tête.
     *
     * @return array<string,string>
     */
    private function euCountryChoices(): array
    {
        $idLang = (int) $this->context->language->id;
        $names  = [];
        foreach (Country::getCountries($idLang, false) as $c) {
            $names[strtoupper((string) $c['iso_code'])] = (string) $c['name'];
        }

        $choices = [];
        foreach (VatValidator::supportedCountries() as $viesCc) {
            if ($viesCc === 'XI') {
                $choices['XI'] = $this->l('Irlande du Nord');
                continue;
            }
            $iso = $viesCc === 'EL' ? 'GR' : $viesCc;
            $choices[$iso] = $names[$iso] ?? $iso;
        }

        // France en tête, le reste par ordre alphabétique.
        $fr = ['FR' => $choices['FR'] ?? 'France'];
        unset($choices['FR']);
        asort($choices);

        return $fr + $choices;
    }

    /**
     * @param FormField[] $fields
     */
    private function extractFieldValue(array $fields, string $name): ?string
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return (string) $field->getValue();
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Configuration BO (getContent)
    // ------------------------------------------------------------------

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submit' . $this->name)) {
            $output .= $this->postProcess();
        }
        if (Tools::isSubmit('snt_ip_generate_api_key')) {
            $newKey = $this->generateApiKey();
            Configuration::updateValue('SNT_IP_API_KEY', $newKey);
            $output .= $this->displayConfirmation($this->l('Nouvelle clé API générée.'));
        }
        if (Tools::isSubmit('snt_ip_purge_logs')) {
            $retention = (int) Configuration::get('SNT_IP_LOG_RETENTION_DAYS');
            $deleted   = $this->getLogger()->repository()->purgeOlderThan($retention > 0 ? $retention : 90);
            // On profite de la purge pour vider aussi les caches INSEE et VIES périmés.
            $ttl = (int) Configuration::get('SNT_IP_INSEE_CACHE_TTL');
            (new InseeCacheRepository())->purgeOlderThan($ttl > 0 ? $ttl : 86400);
            $viesTtl = (int) Configuration::get('SNT_IP_VIES_CACHE_TTL');
            (new ViesCacheRepository())->purgeOlderThan($viesTtl > 0 ? $viesTtl : 604800);
            $output   .= $this->displayConfirmation(sprintf($this->l('%d ligne(s) de log purgée(s).'), $deleted));
        }

        $endpointUrl = $this->getEndpointUrl();
        $currentKey  = (string) Configuration::get('SNT_IP_API_KEY');
        $info = $this->displayInformation(
            '<strong>' . $this->l('URL de l\'endpoint API :') . '</strong><br>'
            . '<code>' . htmlspecialchars($endpointUrl, ENT_QUOTES) . '</code><br>'
            . '<strong>' . $this->l('Clé API actuelle :') . '</strong> '
            . '<code>' . htmlspecialchars($currentKey, ENT_QUOTES) . '</code>'
        );

        return $output . $info . $this->renderConfigForm() . $this->renderRegenerateForm()
            . $this->renderNeedsReviewPanel() . $this->renderLogsPanel();
    }

    private function postProcess(): string
    {
        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            if (Tools::getIsset($key)) {
                $value = Tools::getValue($key);
                if (is_string($value)) {
                    $value = trim($value);
                }
                Configuration::updateValue($key, $value);
            }
        }
        return $this->displayConfirmation($this->l('Configuration enregistrée.'));
    }

    private function renderConfigForm(): string
    {
        $helper = new HelperForm();
        $helper->module                = $this;
        $helper->name_controller       = $this->name;
        $helper->token                 = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex          = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->submit_action         = 'submit' . $this->name;
        $helper->title                 = $this->displayName;
        $helper->show_toolbar          = false;
        $helper->fields_value          = $this->getConfigFormValues();

        return $helper->generateForm([$this->getConfigForm()]);
    }

    private function renderRegenerateForm(): string
    {
        $url = AdminController::$currentIndex . '&configure=' . $this->name
             . '&token=' . Tools::getAdminTokenLite('AdminModules')
             . '&snt_ip_generate_api_key=1';
        return '<div class="panel"><h3>' . $this->l('Clé API endpoint') . '</h3>'
            . '<a class="btn btn-warning" href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
            . 'onclick="return confirm(\'' . $this->l('Régénérer la clé ? Les intégrations existantes devront être mises à jour.') . '\');">'
            . '<i class="icon-refresh"></i> ' . $this->l('Générer une nouvelle clé API')
            . '</a></div>';
    }

    // ------------------------------------------------------------------
    // Écran de lecture : comptes à vérifier + logs
    // ------------------------------------------------------------------

    /**
     * Encart listant les comptes pro acceptés sans validation INSEE
     * (needs_review = 1), avec lien direct vers la fiche client.
     */
    private function renderNeedsReviewPanel(): string
    {
        $rows = $this->getRepository()->findNeedsReview(50);

        $html = '<div class="panel"><h3><i class="icon-warning"></i> '
            . $this->l('Comptes pro à vérifier') . '</h3>';

        if (empty($rows)) {
            return $html . '<p class="text-muted">'
                . $this->l('Aucun compte en attente de vérification. 👍') . '</p></div>';
        }

        $html .= '<p class="text-muted">'
            . $this->l('Raison sociale saisie manuellement car l\'INSEE n\'a pas confirmé le SIRET au moment de l\'inscription.')
            . '</p>';
        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Client') . '</th>'
            . '<th>' . $this->l('E-mail') . '</th>'
            . '<th>' . $this->l('SIRET') . '</th>'
            . '<th>' . $this->l('Raison sociale') . '</th>'
            . '<th>' . $this->l('N° TVA') . '</th>'
            . '<th>' . $this->l('Email comptable') . '</th>'
            . '<th>' . $this->l('Adresse fact. élec.') . '</th>'
            . '<th>' . $this->l('Mis à jour le') . '</th>'
            . '<th></th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $idCustomer = (int) ($r['id_customer'] ?? 0);
            $name = trim((string) ($r['firstname'] ?? '') . ' ' . (string) ($r['lastname'] ?? ''));
            $html .= '<tr>'
                . '<td>' . $this->esc($name !== '' ? $name : ('#' . $idCustomer)) . '</td>'
                . '<td>' . $this->esc((string) ($r['email'] ?? '')) . '</td>'
                . '<td><code>' . $this->esc((string) ($r['siret'] ?? '')) . '</code></td>'
                . '<td>' . $this->esc((string) ($r['company'] ?? '')) . '</td>'
                . '<td>' . $this->esc((string) ($r['vatNumber'] ?? '')) . '</td>'
                . '<td>' . $this->esc((string) ($r['accounting_email'] ?? '')) . '</td>'
                . '<td><code>' . $this->esc((string) ($r['einvoicing_address'] ?? '')) . '</code></td>'
                . '<td>' . $this->esc((string) ($r['date_upd'] ?? '')) . '</td>'
                . '<td><a class="btn btn-default btn-xs" href="' . $this->esc($this->customerLink($idCustomer)) . '">'
                . '<i class="icon-search"></i> ' . $this->l('Voir') . '</a></td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * Tableau des derniers logs internes, avec filtre par type et bouton de purge.
     */
    private function renderLogsPanel(): string
    {
        $repo  = $this->getLogger()->repository();
        $type  = (string) Tools::getValue('snt_ip_log_type', '');
        $types = [
            Logger::TYPE_ACCOUNT_CREATED, Logger::TYPE_ACCOUNT_DEGRADED,
            Logger::TYPE_INSEE_CALL, Logger::TYPE_INSEE_ERROR,
            Logger::TYPE_API_ACCESS, Logger::TYPE_RATE_LIMITED, Logger::TYPE_ALERT_MAIL,
            Logger::TYPE_ADDRESS_LOCKED, Logger::TYPE_VIES_CALL, Logger::TYPE_VIES_ERROR,
        ];
        if ($type !== '' && !in_array($type, $types, true)) {
            $type = '';
        }

        $rows  = $repo->getRecent(100, $type !== '' ? $type : null);
        $total = $repo->countAll();
        $base  = AdminController::$currentIndex . '&configure=' . $this->name
               . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $html = '<div class="panel"><h3><i class="icon-list"></i> '
            . $this->l('Journal du module') . ' <span class="badge">' . (int) $total . '</span></h3>';

        // Filtre par type.
        $html .= '<div class="btn-group" style="margin-bottom:12px;">';
        $html .= '<a class="btn btn-' . ($type === '' ? 'primary' : 'default') . ' btn-sm" href="'
            . $this->esc($base) . '">' . $this->l('Tous') . '</a>';
        foreach ($types as $t) {
            $active = ($type === $t) ? 'primary' : 'default';
            $html  .= '<a class="btn btn-' . $active . ' btn-sm" href="'
                . $this->esc($base . '&snt_ip_log_type=' . $t) . '">' . $this->esc($t) . '</a>';
        }
        $html .= '</div>';

        // Bouton purge.
        $purgeUrl = $base . '&snt_ip_purge_logs=1' . ($type !== '' ? '&snt_ip_log_type=' . $type : '');
        $html .= ' <a class="btn btn-danger btn-sm pull-right" href="' . $this->esc($purgeUrl) . '" '
            . 'onclick="return confirm(\'' . $this->l('Purger les logs au-delà de la rétention configurée ?') . '\');">'
            . '<i class="icon-trash"></i> ' . $this->l('Purger maintenant') . '</a>';

        if (empty($rows)) {
            return $html . '<p class="text-muted">' . $this->l('Aucun log pour ce filtre.') . '</p></div>';
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Date') . '</th>'
            . '<th>' . $this->l('Sévérité') . '</th>'
            . '<th>' . $this->l('Type') . '</th>'
            . '<th>' . $this->l('Client') . '</th>'
            . '<th>' . $this->l('SIRET') . '</th>'
            . '<th>' . $this->l('IP') . '</th>'
            . '<th>' . $this->l('Message') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $idc = (int) ($r['id_customer'] ?? 0);
            $html .= '<tr>'
                . '<td style="white-space:nowrap;">' . $this->esc((string) ($r['date_add'] ?? '')) . '</td>'
                . '<td>' . $this->severityBadge((int) ($r['severity'] ?? 1)) . '</td>'
                . '<td><code>' . $this->esc((string) ($r['type'] ?? '')) . '</code></td>'
                . '<td>' . ($idc > 0 ? '<a href="' . $this->esc($this->customerLink($idc)) . '">#' . $idc . '</a>' : '—') . '</td>'
                . '<td>' . $this->esc((string) ($r['siret'] ?? '')) . '</td>'
                . '<td>' . $this->esc((string) ($r['ip'] ?? '')) . '</td>'
                . '<td>' . $this->esc((string) ($r['message'] ?? '')) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function severityBadge(int $severity): string
    {
        switch ($severity) {
            case Logger::SEVERITY_ERROR:
                return '<span class="label label-danger">' . $this->l('Erreur') . '</span>';
            case Logger::SEVERITY_WARNING:
                return '<span class="label label-warning">' . $this->l('Attention') . '</span>';
            default:
                return '<span class="label label-success">' . $this->l('Info') . '</span>';
        }
    }

    private function customerLink(int $idCustomer): string
    {
        return $this->context->link->getAdminLink('AdminCustomers', true, [], [
            'id_customer'  => $idCustomer,
            'viewcustomer' => 1,
        ]);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function getConfigForm(): array
    {
        return [
            'form' => [
                'legend' => ['title' => $this->l('Configuration')],
                'input'  => [
                    ['type' => 'text', 'label' => $this->l('Clé API INSEE'),          'name' => 'SNT_IP_INSEE_API_KEY', 'size' => 64],
                    ['type' => 'text', 'label' => $this->l('Timeout INSEE (s)'),      'name' => 'SNT_IP_INSEE_TIMEOUT', 'size' => 5],
                    ['type' => 'text', 'label' => $this->l('Allowlist IP endpoint (CSV)'), 'name' => 'SNT_IP_API_IP_ALLOWLIST', 'size' => 64],
                    ['type' => 'text', 'label' => $this->l('Regex AFE'),              'name' => 'SNT_IP_AFE_REGEX', 'size' => 32],
                    ['type' => 'text', 'label' => $this->l('E-mail support (alertes INSEE)'), 'name' => 'SNT_IP_SUPPORT_EMAIL', 'size' => 64],
                    ['type' => 'text', 'label' => $this->l('Rate-limit : appels max / IP'),   'name' => 'SNT_IP_RATELIMIT_MAX', 'size' => 5],
                    ['type' => 'text', 'label' => $this->l('Rate-limit : fenêtre (s)'),        'name' => 'SNT_IP_RATELIMIT_WINDOW', 'size' => 5],
                    ['type' => 'text', 'label' => $this->l('Rétention des logs (jours)'),      'name' => 'SNT_IP_LOG_RETENTION_DAYS', 'size' => 5],
                    ['type' => 'text', 'label' => $this->l('Anti-flood alertes mail (s)'),     'name' => 'SNT_IP_ALERT_THROTTLE', 'size' => 6],
                    ['type' => 'text', 'label' => $this->l('Cache INSEE : durée de vie (s)'),  'name' => 'SNT_IP_INSEE_CACHE_TTL', 'size' => 6],
                    ['type' => 'text', 'label' => $this->l('Timeout VIES (s)'),               'name' => 'SNT_IP_VIES_TIMEOUT', 'size' => 5],
                    ['type' => 'text', 'label' => $this->l('Cache VIES : durée de vie (s)'),   'name' => 'SNT_IP_VIES_CACHE_TTL', 'size' => 7],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Groupe « pro à compléter » (bandeau d\'invitation)'),
                        'name'    => 'SNT_IP_INVITE_GROUP_ID',
                        'desc'    => $this->l('Les clients de ce groupe voient, sur le panier / tunnel, le bandeau les invitant à compléter leurs informations pro (tant qu\'ils ne sont pas passés par le parcours pro). « Désactivé » = aucun bandeau.'),
                        'options' => [
                            'query' => $this->groupOptions(),
                            'id'    => 'id_option',
                            'name'  => 'name',
                        ],
                    ],
                    $this->switchInput('SNT_IP_ALLOW_INDIVIDUAL',   $this->l('Autoriser l\'inscription des particuliers')),
                    $this->switchInput('SNT_IP_SIRET_REQUIRED',     $this->l('SIRET obligatoire si professionnel')),
                    $this->switchInput('SNT_IP_WRITE_COMPANY',      $this->l('Écrire la raison sociale depuis INSEE')),
                    $this->switchInput('SNT_IP_COMPANY_EDITABLE',   $this->l('Autoriser l\'édition libre de la raison sociale')),
                    $this->switchInput('SNT_IP_INSEE_STRICT',       $this->l('Mode strict INSEE (bloquer si injoignable)')),
                    $this->switchInput('SNT_IP_VIES_ENABLE',        $this->l('Activer la vérification VIES de la TVA')),
                    $this->switchInput('SNT_IP_VIES_STRICT',        $this->l('Mode strict VIES (bloquer si injoignable)')),
                    $this->switchInput('SNT_IP_PURGE_ON_DOWNGRADE', $this->l('Purger les données pro si le client repasse particulier')),
                ],
                'submit' => ['title' => $this->l('Enregistrer')],
            ],
        ];
    }

    private function switchInput(string $name, string $label): array
    {
        return [
            'type'    => 'switch',
            'label'   => $label,
            'name'    => $name,
            'is_bool' => true,
            'values'  => [
                ['id' => $name . '_on',  'value' => 1, 'label' => $this->l('Oui')],
                ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('Non')],
            ],
        ];
    }

    private function getConfigFormValues(): array
    {
        $values = [];
        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            $values[$key] = Configuration::get($key);
        }
        return $values;
    }

    /**
     * Options du sélecteur de groupe « pro à compléter » : « Désactivé » (0) puis
     * la liste des groupes clients (id affiché pour repérer le bon groupe).
     *
     * @return array<int,array{id_option:int,name:string}>
     */
    private function groupOptions(): array
    {
        $out = [['id_option' => 0, 'name' => $this->l('— Désactivé (aucun bandeau) —')]];
        foreach (Group::getGroups((int) $this->context->language->id) as $g) {
            $out[] = [
                'id_option' => (int) $g['id_group'],
                'name'      => (string) $g['name'] . ' (#' . (int) $g['id_group'] . ')',
            ];
        }
        return $out;
    }

    private function getEndpointUrl(): string
    {
        $shopUrl = $this->context->shop->getBaseURL(true, true) ?: Tools::getShopDomainSsl(true) . '/';
        return rtrim($shopUrl, '/') . '/index.php?fc=module&module=' . $this->name . '&controller=api';
    }
}
