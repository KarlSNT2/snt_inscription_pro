# Notes d'implémentation — `snt_inscription_pro`

État du module suite aux sessions de développement du 2026-07-24.
Se lit en complément du `snt_inscription_pro_DEV_BRIEF.md`.

---

## 1. Statut par phase

| Phase | Contenu | État |
|---|---|---|
| **1 — Squelette & données** | ObjectModel, repository, services locaux, hooks d'injection/validation/persistance, page config BO, install/uninstall | ✅ Terminée |
| **2 — INSEE & UX live** | Client INSEE, contrôleur AJAX, JS complet (blur, autofill, bouton AFE), re-vérif serveur | ✅ Terminée |
| **3 — Endpoint API** | `controllers/front/api.php` (auth `hash_equals`, HTTPS, allowlist IP) | ⏳ À faire |
| **4 — Finition** | Édition post-inscription approfondie, multiboutique, traductions .xlf, upgrade, logo | ⏳ À faire |

---

## 2. Arborescence livrée

```
snt_inscription_pro/
├── snt_inscription_pro.php          # Module principal (~510 lignes)
├── config.xml
├── index.php                        # Protection racine
├── classes/
│   └── SntInscriptionPro.php        # ObjectModel table module
├── controllers/front/
│   └── validate.php                 # AJAX validation SIRET (Phase 2)
├── src/
│   ├── Service/
│   │   ├── InseeClient.php          # API Sirene 3.11
│   │   ├── InseeResult.php          # DTO
│   │   ├── SiretValidator.php       # Format + Luhn + exception La Poste
│   │   └── VatCalculator.php        # TVA FR depuis SIREN
│   └── Repository/
│       └── ProCustomerRepository.php
├── views/
│   ├── js/registration.js
│   └── css/registration.css
└── upgrade/
    └── upgrade-1.0.1.php            # Squelette
```

---

## 3. Points d'architecture

- **Autoloader PSR-4 minimal** enregistré dans le constructeur du module : namespace `SNT\InscriptionPro\` mappé sur `src/`. Zéro dépendance Composer côté boutique — installation "zip + clic".
- **Convention de nommage** : ObjectModel sans namespace (convention PS) ; services et repository sous `SNT\InscriptionPro\`.
- **Séparation des responsabilités** : `siret` + `company` persistés dans `ps_customer` (colonnes natives, écrites par le CustomerFormatter), `vatNumber` + `afe` en table module `ps_snt_inscription_pro` (UNIQUE sur `id_customer`).
- **Configuration** : toutes les clés `SNT_IP_*` ont des valeurs par défaut à l'install ; la clé API endpoint est générée automatiquement via `bin2hex(random_bytes(24))`.
- **Zéro override thème** : tout passe par les hooks. Les fields sont injectés via `additionalCustomerFormFields`, CSS/JS chargés scoped sur `registration`/`authentication`/`identity`/`order`.

---

## 4. Sécurité serveur

- **`company` verrouillé côté serveur** quand `SNT_IP_COMPANY_EDITABLE=0` : la valeur POST est écrasée par la valeur DB existante avant que le CustomerFormatter ne persiste le Customer.
- **`company` écrit depuis INSEE** (autoritatif) dans `hookValidateCustomerFormFields` : re-appel du client INSEE côté serveur, `setValue()` sur le field company depuis `denominationUniteLegale`. Le blur AJAX n'est jamais source de vérité.
- **Dégradation gracieuse** : par défaut, `SNT_IP_INSEE_STRICT=0`. Si INSEE renvoie 429/timeout/erreur réseau, on accepte un SIRET localement valide (Luhn) + log. Mode strict = refus bloquant.

---

## 5. Compatibilité `PS_B2B_ENABLE`

Le brief interdisait d'activer le mode B2B, mais la boutique de test l'avait actif. Fix appliqué :
`hookAdditionalCustomerFormFields` détecte si `siret`/`company`/`vatNumber` sont **déjà** dans `$params['fields']` (via PS natif B2B ou un autre module). Dans ce cas, on ne les redéclare pas — on met à jour leur `value` et `required` sur les fields existants. Empêche tout doublon serveur, même si le mode B2B est réactivé plus tard.

Un `removeDuplicateFields()` JS reste en filet de sécurité si un thème hardcode aussi les inputs dans son template.

---

## 6. UX front (`registration.js`)

Ordre d'exécution au chargement :
1. `removeDuplicateFields()` — supprime les wrappers de champs qui ne sont pas dans le parent commun avec `is_pro` (indication qu'ils viennent d'ailleurs).
2. `reorderFields()` — place `is_pro` avant le premier `.form-group` du bloc, puis les 4 champs pro à la suite.
3. `applyCompanyReadonly()` — met `readonly` sur company si `SNT_IP_COMPANY_EDITABLE=0`.
4. `injectAfeSirenButton()` — bouton "Utiliser mon SIREN" dans le container direct du champ AFE.
5. `applyVisibility()` — masque les 4 champs pro si `is_pro=0`, gère `required` dynamique.

Blur SIRET :
- Normalisation → Luhn client (avec exception La Poste `356000000`) → si KO : erreur inline.
- Sinon → POST `SNT_IP_VALIDATE_URL` (exposée via `Media::addJsDef`) → spinner → réponse → autofill `company` (forcé si non-éditable) + `vatNumber` (si vide) + statut vert/gris.

Variables exposées au JS via `Media::addJsDef` :
- `SNT_IP_VALIDATE_URL` (URL du contrôleur AJAX validate)
- `SNT_IP_COMPANY_EDITABLE`
- `SNT_IP_AFE_REGEX`
- `SNT_IP_SIRET_REQUIRED`

---

## 7. Bugs rencontrés & fixes

| Symptôme | Cause | Fix |
|---|---|---|
| JS n'a aucun effet sur la page inscription | `hookActionFrontControllerSetMedia` filtrait sur `authentication` uniquement ; PS 8 utilise `registration` | Liste élargie : `registration`, `authentication`, `identity`, `order` |
| Champs pro visibles quand "Particulier" coché | Sélecteur `[data-snt-ip-field]` inventé, jamais présent dans le DOM PS | Cibler par `input[name="..."]` puis `.closest('.form-group')` |
| Fatal error "Method name must be a string" au submit | `setConstraints(['snt_ip_readonly' => true])` : PS itère les constraints comme noms de méthodes `Validate::*`, un booléen crashe | Suppression du `setConstraints`, readonly géré côté JS |
| Doublons SIRET/company en bas du formulaire | Mode B2B actif → PS native déjà ces fields, mon hook les rajoutait | Détection `$params['fields']` : réutilise le field existant s'il est là |
| Erreur SQL "near 'LIMIT 1'" au submit | `DbQuery->limit(1)` + `Db::getRow()` qui ajoute lui-même un `LIMIT 1` → double LIMIT | Suppression de `->limit(1)` dans `findByCustomer()` |
| Bouton "Utiliser mon SIREN" en pleine largeur | Thème étirait tous les `.btn` à 100% | CSS `display:inline-block !important; width:auto !important` sur `.snt-ip-btn-siren` |
| Feedback INSEE aligné à gauche à côté du label | `<span>` appendé au `.form-group`, hors du container `.col-md-*` | Append dans le container direct de l'input + `display: block; margin-top` |

---

## 8. À faire pour finaliser

- **Phase 3 — Endpoint API** :
  - `controllers/front/api.php` avec classe `Snt_inscription_proApiModuleFrontController`.
  - Override `init()` pour court-circuiter le cycle front, `hash_equals(SNT_IP_API_KEY, header('X-Api-Key'))`.
  - Contrôle HTTPS (`Tools::usingSecureMode()` ou `HTTP_X_FORWARDED_PROTO === 'https'`).
  - Allowlist IP (`SNT_IP_API_IP_ALLOWLIST`, CSV).
  - Sortie JSON stricte `{id_customer, vatNumber, afe}`, codes `200/400/401/403/404`.
  - Test `curl` documenté.

- **Phase 4 — Finition** :
  - Édition compte (page `identity`) : vérifier le pré-remplissage et la re-vérif INSEE au changement de SIRET.
  - Multiboutique : s'assurer que `Configuration::get()` respecte le contexte shop en écriture/lecture (`Shop::getContext()`).
  - Traductions PS8 : fichiers `.xlf` sous `translations/`.
  - `index.php` de protection dans les sous-dossiers (best practice).
  - `logo.png` du module.
  - README/runbook admin.
  - Retirer le "(optionnel)" du label SIRET côté client (swap dynamique du texte du label selon `is_pro`).

---

## 9. Test manuel de bout en bout (Phase 1+2)

- [x] Installation propre sur PS 8.x, table créée, colonne `ps_customer.siret` présente, hooks enregistrés, clé endpoint générée.
- [x] Inscription particulier : champs pro masqués, comportement natif préservé.
- [x] Inscription pro : SIRET vérifié au blur INSEE, TVA auto-calculée, autofill company, bouton AFE fonctionnel, persistance OK (`ps_customer.siret/company` + `ps_snt_inscription_pro.vatNumber/afe`).
- [ ] Édition compte (à valider par Karl).
- [ ] Downgrade (à valider par Karl).
- [ ] Suppression client → purge table module (à valider).
- [ ] INSEE injoignable en mode strict (à valider).
