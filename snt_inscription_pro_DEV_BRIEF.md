# Brief de développement — Module PrestaShop `snt_inscription_pro`

> Document auto-porteur destiné à Claude Code. Il contient tout le contexte nécessaire pour développer le module sans dépendance à une conversation antérieure.

---

## 1. Objectif

Enrichir l'inscription client PrestaShop pour capter et vérifier les données professionnelles requises par la réforme de la facturation électronique française, sans passer par le mode B2B global de PrestaShop et **sans override de thème**.

Concrètement, le module :
1. Ajoute au formulaire d'inscription (et d'édition du compte) un choix « Particulier / Professionnel ».
2. Si « Professionnel », affiche : **SIRET** (obligatoire, vérifié via API INSEE), **N° TVA intracom.** (facultatif, auto-calculé), **Adresse de Facturation Électronique / AFE** (facultatif).
3. Persiste le SIRET dans `ps_customer.siret`, la raison sociale INSEE dans `ps_customer.company`, et `vatNumber` + `afe` dans une table propre au module (relation 1-1 avec le client).
4. Expose un **endpoint API REST en lecture seule** renvoyant `vatNumber` + `afe` pour un `id_customer` donné (consommé par un ERP / n8n externe).
5. Est **installable et configurable en quelques clics** sur n'importe quel PrestaShop (multi-boutiques internes), via une page de config unique : clé API INSEE + clé API endpoint générée automatiquement, et c'est parti.

## 2. Cible & contraintes techniques

- **PrestaShop 8.x** (PHP 8.1+). Compat multiboutique attendue.
- **Zéro (ou quasi zéro) override de thème.** Tout passe par des hooks. Rien dans `themes/`.
- **Pas d'activation du mode B2B global** (`PS_B2B_ENABLE`). Conséquence directe : en PS 8, les champs `company`/`siret` ne sont PAS fournis par le formulaire natif hors B2B → **le module doit injecter lui-même tous ses champs** via `additionalCustomerFormFields`.
- **Pas d'appel bloquant non maîtrisé dans le cycle de requête** : l'appel INSEE se fait en AJAX (validation au blur) et en re-vérification serveur au submit avec **timeout court** et **dégradation gracieuse** (voir §7).
- Code propre, réutilisable, tout piloté par la configuration BO. Pas de valeur codée en dur (clés, URL, timeouts, regex AFE).

## 3. Décisions de conception figées

| Sujet | Décision |
|---|---|
| Type de formulaire | Formulaire natif enrichi d'un toggle « Pro », pas de contrôleur d'inscription dédié |
| Détection « pro » | Un radio `is_pro` (virtuel, non persisté en colonne) ; côté serveur, « pro » = `is_pro=1`. La présence d'une ligne dans la table module + `siret` renseigné matérialise le statut pro |
| Stockage SIRET | `ps_customer.siret` (colonne native ; l'install la crée si absente par sécurité) |
| Stockage company | `ps_customer.company`, **écrit par le serveur depuis la réponse INSEE**, jamais depuis le champ client |
| Stockage vatNumber / afe | Table module `ps_snt_inscription_pro` |
| TVA intracom. | **Calculée** depuis le SIREN (pas d'appel INSEE dédié), champ modifiable |
| AFE | Pour l'instant = SIREN → regex par défaut `^\d{9}$` (configurable). Bouton « Utiliser mon SIREN » pour pré-remplir |
| Édition compte | SIRET éditable (re-validé INSEE), company en lecture seule par défaut (flag `SNT_IP_COMPANY_EDITABLE`) |
| Downgrade (client décoche « pro ») | Par défaut : on **conserve** les données, on ne les **exige** plus (non destructif). Configurable via `SNT_IP_PURGE_ON_DOWNGRADE` |
| Suppression client | Purge de la ligne module via hook `actionObjectCustomerDeleteAfter` (pas de FK cascade — MyISAM encore possible) |
| Endpoint API | Renvoie **strictement** `id_customer`, `vatNumber`, `afe`. `siret`/`company` sont déjà exposés par le webservice PrestaShop natif |

## 4. Arborescence cible

```
snt_inscription_pro/
├── snt_inscription_pro.php            # Module principal : install/uninstall, hooks, getContent
├── config.xml
├── logo.png
├── classes/
│   └── SntInscriptionPro.php          # ObjectModel de la table module
├── controllers/
│   └── front/
│       ├── api.php                    # Endpoint REST lecture seule (vatNumber+afe)
│       └── validate.php               # AJAX : validation SIRET au blur (formulaire)
├── src/
│   ├── Service/
│   │   ├── InseeClient.php            # Wrapper API INSEE (clé, timeout, parsing)
│   │   ├── VatCalculator.php          # Calcul TVA FR depuis SIREN
│   │   └── SiretValidator.php         # Format + Luhn (SIRET 14 / SIREN 9)
│   └── Repository/
│       └── ProCustomerRepository.php  # Lecture/écriture table module (upsert, get, delete)
├── views/
│   ├── js/registration.js             # Toggle + validation live + autofill + bouton AFE
│   ├── css/registration.css
│   └── templates/admin/configure.tpl  # (si HelperForm insuffisant)
├── upgrade/
│   └── upgrade-1.0.1.php              # Squelette de migration
└── translations/ (ou traductions via .xlf PS8)
```

## 5. Modèle de données

Table créée à l'install (`ObjectModel` `SntInscriptionPro`) :

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_snt_inscription_pro` (
  `id_snt_inscription_pro` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_customer`            INT(11) UNSIGNED NOT NULL,
  `vatNumber`              VARCHAR(20)  DEFAULT NULL,
  `afe`                    VARCHAR(34)  DEFAULT NULL,
  `date_add`               DATETIME     NOT NULL,
  `date_upd`               DATETIME     NOT NULL,
  PRIMARY KEY (`id_snt_inscription_pro`),
  UNIQUE KEY `uniq_id_customer` (`id_customer`)
) ENGINE=ENGINE_PLACEHOLDER DEFAULT CHARSET=utf8mb4;
```

- Clé primaire **technique** `id_snt_inscription_pro` (convention `ObjectModel`) + **index UNIQUE** sur `id_customer` pour garantir le 1-1. Ne PAS mettre `id_customer` en PK directe.
- `afe` en `VARCHAR(34)` pour rester tolérant à des formats futurs (SIREN aujourd'hui, éventuellement identifiants plus longs demain). La validation applique la regex configurable.
- Utiliser `_DB_PREFIX_` et `_MYSQL_ENGINE_` dans le code réel.

`SntInscriptionPro` (ObjectModel) `$definition` :
```php
public static $definition = [
    'table'   => 'snt_inscription_pro',
    'primary' => 'id_snt_inscription_pro',
    'fields'  => [
        'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
        'vatNumber'   => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 20],
        'afe'         => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 34],
        'date_add'    => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        'date_upd'    => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
    ],
];
```

## 6. Configuration (clés `Configuration::` — respecter le contexte multiboutique)

| Clé | Type | Défaut | Rôle |
|---|---|---|---|
| `SNT_IP_INSEE_API_KEY` | string | `''` | Clé API INSEE (portail api-sirene 3.11) |
| `SNT_IP_INSEE_TIMEOUT` | int | `3` | Timeout curl INSEE (secondes) |
| `SNT_IP_API_KEY` | string | *(généré)* | Clé de l'endpoint API du module |
| `SNT_IP_API_IP_ALLOWLIST` | string | `''` | Liste d'IP autorisées pour l'endpoint (CSV, vide = pas de filtre) |
| `SNT_IP_SIRET_REQUIRED` | bool | `1` | SIRET obligatoire si pro |
| `SNT_IP_AFE_REGEX` | string | `^\d{9}$` | Regex de validation AFE |
| `SNT_IP_WRITE_COMPANY` | bool | `1` | Écrire `ps_customer.company` depuis INSEE |
| `SNT_IP_COMPANY_EDITABLE` | bool | `0` | Autoriser l'édition libre de company côté client |
| `SNT_IP_INSEE_STRICT` | bool | `0` | Strict = refuse l'inscription si INSEE injoignable ; sinon dégradation gracieuse (format+Luhn) |
| `SNT_IP_PURGE_ON_DOWNGRADE` | bool | `0` | Purger les données pro si le client repasse « particulier » |

**Page de config (getContent)** : un seul écran. Champs ci-dessus + un **bouton « Générer une nouvelle clé API »** (régénère `SNT_IP_API_KEY`) + affichage en lecture seule de **l'URL de l'endpoint** avec bouton copier. HelperForm suffit ; passer à un template admin uniquement si besoin du bouton copier/générer en JS.

## 7. Intégration INSEE

**Endpoint** : `GET https://api.insee.fr/api-sirene/3.11/siret/{siret}`
**Auth** (confirmée) : header `X-INSEE-Api-Key-Integration: {clé}` + `accept: application/json`.

Implémentation de référence (à intégrer dans `InseeClient`, avec timeout et gestion d'erreurs) :

```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.insee.fr/api-sirene/3.11/siret/' . $siret);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) Configuration::get('SNT_IP_INSEE_TIMEOUT'));
curl_setopt($ch, CURLOPT_TIMEOUT, (int) Configuration::get('SNT_IP_INSEE_TIMEOUT'));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'X-INSEE-Api-Key-Integration: ' . Configuration::get('SNT_IP_INSEE_API_KEY'),
]);
$raw      = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
```

**Parsing de la réponse V3.11** (objet `->etablissement`) :
- `siren` : `etablissement.siren`
- Raison sociale (personne morale) : `etablissement.uniteLegale.denominationUniteLegale`
- Fallback personne physique : concaténer `prenomUsuelUniteLegale` (ou `prenom1UniteLegale`) + `nomUniteLegale`
- État de l'établissement : `etablissement.periodesEtablissement[0].etatAdministratifEtablissement` (`A` = actif, `F` = fermé)

**Codes HTTP à gérer** : `200` trouvé · `400` requête invalide · `401/403` clé invalide · `404` non trouvé · `429` rate limit · autre/timeout → indisponible.

**Comportement établissement fermé (`F`)** : ne pas bloquer, avertir en douceur (« Établissement marqué fermé à l'INSEE, vérifiez le SIRET »).

**Dégradation gracieuse (`SNT_IP_INSEE_STRICT=0`, défaut)** : si INSEE renvoie une erreur transport/timeout/429, on **accepte** un SIRET localement valide (format 14 chiffres + Luhn) et on log l'incident. `company` reste alors non renseignée (ou dernière valeur connue en édition). En mode strict, on refuse et on affiche une erreur bloquante.

**Rate limit** : l'API Sirene est limitée (~30 req/min historiquement). Sans impact au blur (volume par utilisateur négligeable), mais gérer proprement le `429`.

*Note : `recherche-entreprises.api.gouv.fr` (sans clé) reste une piste de repli/autofill possible, non implémentée dans cette version.*

## 8. Algorithmes

**Luhn** (SIRET 14 chiffres, SIREN 9 chiffres) — `SiretValidator` :
- SIRET valide = 14 chiffres + Luhn OK.
- SIREN valide = 9 chiffres + Luhn OK.
- **Exception connue** : le SIREN de La Poste `356000000` ne passe pas Luhn → prévoir une allowlist d'exceptions (constante) pour ne pas rejeter à tort.

**Calcul TVA FR** — `VatCalculator::fromSiren($siren)` :
```php
$siren = (int) substr($siret, 0, 9);
$key   = (12 + 3 * ($siren % 97)) % 97;
return 'FR' . str_pad((string) $key, 2, '0', STR_PAD_LEFT) . $siren;
```

## 9. Hooks

| Hook | Rôle |
|---|---|
| `additionalCustomerFormFields` | Injecter `is_pro` (radio) + `siret` + `company` + `vatNumber` + `afe`. Se déclenche sur inscription ET édition compte (CustomerFormatter). En édition, pré-remplir via `FormField::setValue()` depuis `ps_customer` + table module |
| `validateCustomerFormFields` | Validation serveur : si `is_pro` → SIRET requis (si `SNT_IP_SIRET_REQUIRED`), format+Luhn, **re-vérif INSEE serveur**, regex AFE, format TVA. Ajouter les erreurs via `$field->addError()` |
| `actionCustomerAccountAdd` | Persistance à la création : écrire `siret`(+`company`) sur le customer, upsert `vatNumber`/`afe` en table module |
| `actionCustomerAccountUpdate` | Idem à l'édition. Gérer le downgrade (`SNT_IP_PURGE_ON_DOWNGRADE`) |
| `actionObjectCustomerDeleteAfter` | Supprimer la ligne module correspondante |
| `actionFrontControllerSetMedia` | Charger `registration.js` + `registration.css` uniquement sur `authentication` et `identity` (`$this->context->controller->php_self`) |

> ⚠️ Sécurité : la validation serveur dans `validateCustomerFormFields`/`actionCustomerAccountAdd` **ne fait jamais confiance** au résultat AJAX du blur. Elle refait la vérif INSEE et recalcule/écrit `company` elle-même.

## 10. Endpoint API (`controllers/front/api.php`)

- Classe `Snt_inscription_proApiModuleFrontController extends ModuleFrontController`.
- **Ne pas dérouler le cycle front complet** : override `init()`, sortir en JSON avant tout template/session pour rester léger (utiliser `Db`/`Configuration` directement, `die()` à la fin).
- **Auth** : header `X-Api-Key` comparé en `hash_equals(Configuration::get('SNT_IP_API_KEY'), $provided)`.
- **HTTPS obligatoire** : contrôler `Tools::usingSecureMode()` OU `HTTP_X_FORWARDED_PROTO === 'https'` (les boutiques sont derrière un proxy SSL type IONOS).
- **Allowlist IP** optionnelle (`SNT_IP_API_IP_ALLOWLIST`).
- **Entrée** : `id_customer` (cast int, `Tools::getValue`).
- **Sortie** :
  ```json
  { "id_customer": 42, "vatNumber": "FR32123456789", "afe": "123456789" }
  ```
- **Codes** : `200` OK · `401` clé absente/invalide · `403` IP non autorisée / non HTTPS · `404` client sans données pro · `400` id_customer manquant/invalide.
- `header('Content-Type: application/json')` puis `die(json_encode(...))`.
- URL : `https://boutique/index.php?fc=module&module=snt_inscription_pro&controller=api&id_customer=42` (documenter l'URL friendly si activée).

## 11. UX / Front (`registration.js`)

1. Au chargement : si `is_pro` non coché → masquer `.snt-pro-fields`.
2. `is_pro` change → afficher/masquer les champs pro ; retirer/rendre requis le SIRET selon config.
3. `siret` blur → si 14 chiffres + Luhn OK : afficher un spinner, appeler l'AJAX `validate` :
   - succès → coche verte + raison sociale affichée ; **auto-remplir `vatNumber`** (valeur renvoyée / calculée) ; remplir `company` (lecture seule sauf `SNT_IP_COMPANY_EDITABLE`) ; activer le bouton AFE.
   - échec / SIRET inconnu → message d'erreur inline non bloquant selon mode.
4. Bouton **« Utiliser mon SIREN »** à côté de l'AFE → copie le SIREN (9 premiers chiffres du SIRET validé) dans le champ `afe`.
5. Champs `vatNumber` et `afe` restent librement modifiables par l'utilisateur.
6. Aucune dépendance à jQuery imposée par le module (mais réutiliser celui du thème s'il est présent). CSS minimal, préfixé `.snt-ip-*`, sans écraser le thème.

## 12. Critères d'acceptation

- [ ] Installation sur un PS 8.x vierge : table créée, colonne `ps_customer.siret` présente, hooks enregistrés, clé endpoint générée, aucune erreur.
- [ ] Inscription particulier : champs pro masqués, comportement natif inchangé.
- [ ] Inscription pro : SIRET vérifié au blur, TVA auto-calculée, bouton AFE fonctionnel ; au submit, `siret` + `company` en `ps_customer`, `vatNumber` + `afe` en table module, ligne unique par client.
- [ ] Édition compte : valeurs pré-remplies ; modification du SIRET → re-vérif INSEE + re-dérivation company/TVA ; company non éditable par défaut.
- [ ] Downgrade : comportement conforme à `SNT_IP_PURGE_ON_DOWNGRADE`.
- [ ] Suppression client : ligne module supprimée.
- [ ] INSEE injoignable : mode non-strict laisse passer un SIRET valide localement + log ; mode strict bloque proprement.
- [ ] Endpoint : `200` avec bonne clé + client pro ; `401` sans/mauvaise clé ; `403` hors HTTPS/IP ; `404` client non pro ; JSON strict `{id_customer, vatNumber, afe}`.
- [ ] Zéro fichier modifié dans `themes/`.
- [ ] Désinstallation : table supprimée, hooks retirés, config nettoyée (proposer une option « conserver les données »).

## 13. Découpage en phases (pour Claude Code)

**Phase 1 — Squelette & données**
- `snt_inscription_pro.php` (install/uninstall/enable, enregistrement hooks, création table + colonne siret, génération clé API).
- `SntInscriptionPro` (ObjectModel).
- `ProCustomerRepository` (get / upsert / deleteByCustomer).
- Page `getContent` avec tous les champs de config + bouton génération clé + affichage URL endpoint.
- `SiretValidator` (format + Luhn + exceptions) et `VatCalculator`.
- Injection des champs (`additionalCustomerFormFields`) + validation **locale** (`validateCustomerFormFields`) + persistance (`actionCustomerAccountAdd`/`Update`, `actionObjectCustomerDeleteAfter`).
- CSS/JS de base : toggle uniquement.

**Phase 2 — INSEE & UX live**
- `InseeClient` (appel, timeout, parsing, codes d'erreur, dégradation gracieuse).
- Contrôleur AJAX `validate.php`.
- `registration.js` complet : validation blur, autofill company + TVA, bouton « Utiliser mon SIREN », messages inline.
- Re-vérification INSEE serveur au submit + écriture `company`.

**Phase 3 — Endpoint API**
- `api.php` : auth `hash_equals`, contrôle HTTPS + allowlist IP, sortie JSON, codes d'erreur. Test avec `curl`.

**Phase 4 — Finition**
- Édition post-inscription (pré-remplissage identity, gestion downgrade).
- Multiboutique (contexte config), traductions PS8 (.xlf), script d'upgrade, README/runbook.

## 14. Non-objectifs (à NE PAS faire)

- Ne pas activer `PS_B2B_ENABLE`.
- Ne pas créer de contrôleur d'inscription dédié / de tunnel séparé.
- Ne pas modifier le thème.
- Ne pas exposer `siret`/`company` dans l'endpoint module (déjà couverts par le webservice natif).
- Ne pas affecter automatiquement de groupe client (hors périmètre).
- Ne pas stocker la clé API en clair dans les logs.
