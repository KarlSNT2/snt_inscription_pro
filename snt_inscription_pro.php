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

require_once __DIR__ . '/classes/SntInscriptionPro.php';

use SNT\InscriptionPro\Repository\ProCustomerRepository;
use SNT\InscriptionPro\Service\InseeClient;
use SNT\InscriptionPro\Service\InseeResult;
use SNT\InscriptionPro\Service\SiretValidator;
use SNT\InscriptionPro\Service\VatCalculator;

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
    ];

    private const HOOKS = [
        'additionalCustomerFormFields',
        'validateCustomerFormFields',
        'actionCustomerAccountAdd',
        'actionCustomerAccountUpdate',
        'actionObjectCustomerDeleteAfter',
        'actionFrontControllerSetMedia',
    ];

    private ?ProCustomerRepository $repository = null;

    public function __construct()
    {
        $this->name          = 'snt_inscription_pro';
        $this->tab           = 'front_office_features';
        $this->version       = '1.0.0';
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

        if (!$this->installTable() || !$this->ensureSiretColumn()) {
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
        }

        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'snt_inscription_pro` (
            `id_snt_inscription_pro` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer`            INT(11) UNSIGNED NOT NULL,
            `vatNumber`              VARCHAR(20)  DEFAULT NULL,
            `afe`                    VARCHAR(34)  DEFAULT NULL,
            `date_add`               DATETIME     NOT NULL,
            `date_upd`               DATETIME     NOT NULL,
            PRIMARY KEY (`id_snt_inscription_pro`),
            UNIQUE KEY `uniq_id_customer` (`id_customer`)
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

        // is_pro : toujours ajouté (n'est jamais natif).
        $additions[] = (new FormField())
            ->setName('is_pro')
            ->setType('radio-buttons')
            ->setLabel($this->l('Type de compte'))
            ->setValue($prefill['is_pro'])
            ->setAvailableValues([
                '0' => $this->l('Particulier'),
                '1' => $this->l('Professionnel'),
            ])
            ->setRequired(true);

        // siret : réutilise le field existant si présent, sinon on l'ajoute.
        if (isset($existingByName['siret'])) {
            if ($prefill['siret'] !== '') {
                $existingByName['siret']->setValue($prefill['siret']);
            }
            $existingByName['siret']->setRequired((bool) Configuration::get('SNT_IP_SIRET_REQUIRED'));
        } else {
            $additions[] = (new FormField())
                ->setName('siret')
                ->setType('text')
                ->setLabel($this->l('SIRET'))
                ->setValue($prefill['siret'])
                ->setRequired((bool) Configuration::get('SNT_IP_SIRET_REQUIRED'));
        }

        // company : idem. Le readonly côté client est géré par le JS via
        // SNT_IP_COMPANY_EDITABLE ; le serveur verrouille dans validate.
        if (isset($existingByName['company'])) {
            if ($prefill['company'] !== '') {
                $existingByName['company']->setValue($prefill['company']);
            }
        } else {
            $additions[] = (new FormField())
                ->setName('company')
                ->setType('text')
                ->setLabel($this->l('Raison sociale'))
                ->setValue($prefill['company']);
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

        // afe : toujours ajouté (n'existe pas dans les fields natifs PS).
        $additions[] = (new FormField())
            ->setName('afe')
            ->setType('text')
            ->setLabel($this->l('Adresse de Facturation Électronique (AFE)'))
            ->setValue($prefill['afe']);

        return $additions;
    }

    /**
     * Validation serveur locale (Phase 1 : format + Luhn + regex AFE).
     * La re-vérif INSEE est ajoutée en Phase 2.
     * @param array{fields: FormField[]} $params
     */
    public function hookValidateCustomerFormFields($params): array
    {
        $fields = $params['fields'] ?? [];
        $errors = [];

        $isPro   = $this->extractFieldValue($fields, 'is_pro') === '1';

        // Verrouillage serveur de `company` quand l'édition libre est désactivée.
        // On écrase la valeur POST par la valeur en base (ou vide en création)
        // avant que le CustomerFormatter ne persiste le Customer.
        if (!(bool) Configuration::get('SNT_IP_COMPANY_EDITABLE')) {
            foreach ($fields as $field) {
                if ($field->getName() === 'company') {
                    $existing = ($this->context->customer && (int) $this->context->customer->id > 0)
                        ? (string) $this->context->customer->company
                        : '';
                    $field->setValue($existing);
                }
            }
        }

        if (!$isPro) {
            return $errors;
        }

        $siretField   = null;
        $companyField = null;
        $vatField     = null;

        foreach ($fields as $field) {
            switch ($field->getName()) {
                case 'siret':
                    $siretField = $field;
                    $siret = SiretValidator::normalize((string) $field->getValue());
                    if ((bool) Configuration::get('SNT_IP_SIRET_REQUIRED') && $siret === '') {
                        $field->addError($this->l('Le SIRET est obligatoire pour un compte professionnel.'));
                    } elseif ($siret !== '' && !SiretValidator::isSiret($siret)) {
                        $field->addError($this->l('SIRET invalide : 14 chiffres attendus et clé de contrôle Luhn incorrecte.'));
                    }
                    break;

                case 'company':
                    $companyField = $field;
                    break;

                case 'afe':
                    $afe   = trim((string) $field->getValue());
                    $regex = (string) Configuration::get('SNT_IP_AFE_REGEX');
                    if ($afe !== '' && $regex !== '' && @preg_match('/' . $regex . '/', $afe) !== 1) {
                        $field->addError($this->l('AFE au format invalide.'));
                    }
                    break;

                case 'vatNumber':
                    $vatField = $field;
                    $vat = strtoupper(preg_replace('/\s+/', '', (string) $field->getValue()));
                    if ($vat !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat)) {
                        $field->addError($this->l('N° TVA intracommunautaire au format invalide.'));
                    }
                    break;
            }
        }

        // Re-vérification INSEE serveur (n'est jamais dérivée du blur AJAX).
        $this->applyInseeCheck($siretField, $companyField, $vatField);

        return $errors;
    }

    /**
     * Ré-appelle l'INSEE côté serveur pour ancrer company (autoritatif) et
     * pré-remplir vatNumber si l'utilisateur ne l'a pas fourni. Gère les modes
     * strict / dégradation gracieuse via `SNT_IP_INSEE_STRICT`.
     */
    private function applyInseeCheck(?FormField $siretField, ?FormField $companyField, ?FormField $vatField): void
    {
        if (!$siretField) {
            return;
        }
        $siret = SiretValidator::normalize((string) $siretField->getValue());
        if ($siret === '' || !SiretValidator::isSiret($siret)) {
            return; // erreurs déjà remontées le cas échéant
        }

        $strict = (bool) Configuration::get('SNT_IP_INSEE_STRICT');
        $result = (new InseeClient())->fetchSiret($siret);

        switch ($result->status) {
            case InseeResult::STATUS_FOUND:
                if ($companyField && (bool) Configuration::get('SNT_IP_WRITE_COMPANY') && $result->company) {
                    $companyField->setValue($result->company);
                }
                if ($vatField && trim((string) $vatField->getValue()) === '') {
                    $vatField->setValue((string) VatCalculator::fromSiret($siret));
                }
                break;

            case InseeResult::STATUS_NOT_FOUND:
                if ($strict) {
                    $siretField->addError($this->l('SIRET introuvable dans le répertoire INSEE.'));
                }
                break;

            case InseeResult::STATUS_INVALID_KEY:
            case InseeResult::STATUS_BAD_REQUEST:
                if ($strict) {
                    $siretField->addError($this->l('Impossible de vérifier le SIRET auprès de l\'INSEE.'));
                }
                break;

            case InseeResult::STATUS_RATE_LIMITED:
            case InseeResult::STATUS_UNAVAILABLE:
            default:
                if ($strict) {
                    $siretField->addError($this->l('Service INSEE momentanément indisponible, merci de réessayer.'));
                }
                // Mode non-strict : SIRET est localement valide → on laisse passer.
                break;
        }
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
     * Chargement CSS/JS strictement sur les pages exposant le formulaire client.
     * PS 8 utilise `registration` (création), `authentication` (login),
     * `identity` (édition compte) et `order` (inscription au checkout).
     */
    public function hookActionFrontControllerSetMedia($params): void
    {
        $selfName = $this->context->controller->php_self ?? null;
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
        $isPro = (string) $isPro === '1';

        if (!$isPro) {
            $this->handleDowngrade($customer);
            return;
        }

        $siret     = SiretValidator::normalize((string) Tools::getValue('siret', ''));
        $vatNumber = strtoupper(preg_replace('/\s+/', '', (string) Tools::getValue('vatNumber', '')));
        $afe       = trim((string) Tools::getValue('afe', ''));

        // Auto-calcul TVA si vide et SIREN dispo.
        if ($vatNumber === '' && strlen($siret) >= 9) {
            $vatNumber = (string) VatCalculator::fromSiret($siret);
        }

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

        $this->getRepository()->upsert(
            $idCustomer,
            $vatNumber !== '' ? $vatNumber : null,
            $afe       !== '' ? $afe       : null
        );
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getRepository(): ProCustomerRepository
    {
        if ($this->repository === null) {
            $this->repository = new ProCustomerRepository();
        }
        return $this->repository;
    }

    /**
     * @param array{is_pro:string, siret:string, company:string, vatNumber:string, afe:string}
     */
    private function prefillFromCustomer(?Customer $customer): array
    {
        $defaults = ['is_pro' => '0', 'siret' => '', 'company' => '', 'vatNumber' => '', 'afe' => ''];
        if (!$customer instanceof Customer || (int) $customer->id <= 0) {
            return $defaults;
        }
        $row = $this->getRepository()->findByCustomer((int) $customer->id);
        $hasPro = !empty($customer->siret) || $row !== null;
        return [
            'is_pro'    => $hasPro ? '1' : '0',
            'siret'     => (string) ($customer->siret ?? ''),
            'company'   => (string) ($customer->company ?? ''),
            'vatNumber' => (string) ($row['vatNumber'] ?? ''),
            'afe'       => (string) ($row['afe'] ?? ''),
        ];
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

        $endpointUrl = $this->getEndpointUrl();
        $currentKey  = (string) Configuration::get('SNT_IP_API_KEY');
        $info = $this->displayInformation(
            '<strong>' . $this->l('URL de l\'endpoint API :') . '</strong><br>'
            . '<code>' . htmlspecialchars($endpointUrl, ENT_QUOTES) . '</code><br>'
            . '<strong>' . $this->l('Clé API actuelle :') . '</strong> '
            . '<code>' . htmlspecialchars($currentKey, ENT_QUOTES) . '</code>'
        );

        return $output . $info . $this->renderConfigForm() . $this->renderRegenerateForm();
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
                    $this->switchInput('SNT_IP_SIRET_REQUIRED',     $this->l('SIRET obligatoire si professionnel')),
                    $this->switchInput('SNT_IP_WRITE_COMPANY',      $this->l('Écrire la raison sociale depuis INSEE')),
                    $this->switchInput('SNT_IP_COMPANY_EDITABLE',   $this->l('Autoriser l\'édition libre de la raison sociale')),
                    $this->switchInput('SNT_IP_INSEE_STRICT',       $this->l('Mode strict INSEE (bloquer si injoignable)')),
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

    private function getEndpointUrl(): string
    {
        $shopUrl = $this->context->shop->getBaseURL(true, true) ?: Tools::getShopDomainSsl(true) . '/';
        return rtrim($shopUrl, '/') . '/index.php?fc=module&module=' . $this->name . '&controller=api';
    }
}
