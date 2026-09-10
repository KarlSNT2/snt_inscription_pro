/**
 * SNT Inscription Pro — front logic.
 *  - Réordonne le DOM : is_pro + champs pro tout en haut du bloc formulaire.
 *  - Toggle show/hide selon `is_pro` + gestion `required` dynamique.
 *  - Parcours FR (deux modes, au choix du client) :
 *      • saisie directe du SIRET → appel AJAX INSEE → aperçu (raison sociale +
 *        adresse) sous le champ ;
 *      • recherche par raison sociale + code postal → liste d'établissements →
 *        la sélection renseigne le SIRET (autoritatif) et déclenche le même aperçu.
 *    Le SIRET renseigne le n° de TVA (calcul FR, modifiable). L'AFE reste un champ
 *    libre, surmonté d'une barre de raccourcis (« Utiliser mon SIREN », « Utiliser
 *    mon SIRET », « Saisie manuelle ») qui la remplit ou y place le focus sur
 *    demande. La re-vérif serveur au submit fait foi et crée l'adresse siège.
 *  - Fallback saisie manuelle de la raison sociale quand l'INSEE est indisponible.
 *  - Rend `company` readonly quand SNT_IP_COMPANY_EDITABLE = false.
 */
(function () {
    'use strict';

    if (window.console && window.console.debug) {
        window.console.debug('[SNT-IP] registration.js loaded');
    }

    // pro_country (sélecteur pays) + siret (branche FR, saisi directement) +
    // company/vatNumber + phone (création FR) + accounting_email +
    // einvoicing_address. Le SIREN n'est plus demandé : le SIRET est le point
    // d'entrée du parcours FR (vérifié à l'INSEE au blur).
    // L'« administration » n'est plus un champ distinct : c'est la 3ᵉ valeur du
    // radio « Type de compte » (is_pro = '2'), cf. isAdministrationChecked().
    // L'ordre de ce tableau pilote l'ordre d'affichage des champs (cf.
    // reorderFields) : le n° de TVA est placé AU-DESSUS de la raison sociale.
    var PRO_FIELDS = ['pro_country', 'siret', 'vatNumber', 'company', 'phone', 'accounting_email', 'einvoicing_address'];

    var CFG = {
        validateUrl:     window.SNT_IP_VALIDATE_URL || '',
        companyEditable: !!window.SNT_IP_COMPANY_EDITABLE,
        siretRequired:   !!window.SNT_IP_SIRET_REQUIRED,
        viesEnable:      !!window.SNT_IP_VIES_ENABLE,
    };

    var LUHN_EXCEPTIONS_SIREN = ['356000000']; // La Poste

    // Dernier SIRET pour lequel un appel INSEE a déjà été lancé : évite les
    // appels redondants si le `blur` se redéclenche sur la même valeur (focus qui
    // rebondit, autofill, réordonnancement DOM). Réinitialisé dès que le champ
    // SIRET est réédité (listener `input`).
    var lastSearchedSiret = '';

    // ------------------------------------------------------------------
    // Utilitaires DOM
    // ------------------------------------------------------------------

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function fieldByName(name) {
        return document.querySelector('[name="' + name + '"]');
    }

    function wrapperOf(input) {
        if (!input) return null;
        return input.closest('.form-group') || input.parentElement;
    }

    function inlineContainerOf(input) {
        if (!input) return null;
        return input.parentElement || wrapperOf(input);
    }

    function toggleGroup() {
        return wrapperOf(document.querySelector('input[name="is_pro"]'));
    }

    function proWrappers() {
        var seen = [];
        PRO_FIELDS.forEach(function (name) {
            var w = wrapperOf(fieldByName(name));
            if (w && seen.indexOf(w) === -1) {
                seen.push(w);
            }
        });
        return seen;
    }

    // Professionnel ('1') et Administration ('2') sont deux comptes pro :
    // mêmes champs affichés. Seule l'AFE diffère (cf. isAdministrationChecked).
    function isProChecked() {
        var checked = document.querySelector('input[name="is_pro"]:checked');
        if (!checked) { return false; }
        var v = String(checked.value);
        return v === '1' || v === '2';
    }

    function firstDirectFormGroup(parent) {
        try {
            var first = parent.querySelector(':scope > .form-group');
            if (first) return first;
        } catch (e) { /* :scope non supporté */ }
        for (var i = 0; i < parent.children.length; i++) {
            if (parent.children[i].classList.contains('form-group')) {
                return parent.children[i];
            }
        }
        return null;
    }

    /**
     * Supprime les inputs pro hardcodés par le thème en gardant uniquement ceux
     * injectés par notre hook. On identifie les nôtres par le fait qu'ils
     * partagent le même parent direct que le radio `is_pro`.
     */
    function removeDuplicateFields() {
        var toggle = toggleGroup();
        if (!toggle) return;
        var parent = toggle.parentElement;
        if (!parent) return;

        PRO_FIELDS.forEach(function (name) {
            var inputs = document.querySelectorAll('[name="' + name + '"]');
            if (inputs.length <= 1) return;
            Array.prototype.forEach.call(inputs, function (input) {
                var wrapper = wrapperOf(input);
                if (!wrapper) return;
                if (wrapper.parentElement !== parent && wrapper.parentNode) {
                    wrapper.parentNode.removeChild(wrapper);
                }
            });
        });
    }

    // ------------------------------------------------------------------
    // Réordonnement + visibilité
    // ------------------------------------------------------------------

    function reorderFields() {
        var toggle = toggleGroup();
        if (!toggle) return;
        var parent = toggle.parentElement;
        if (!parent) return;

        var anchor = firstDirectFormGroup(parent);
        if (anchor && anchor !== toggle) {
            parent.insertBefore(toggle, anchor);
        } else if (!anchor) {
            parent.insertBefore(toggle, parent.firstChild);
        }

        var previous = toggle;
        proWrappers().forEach(function (wrapper) {
            if (wrapper.parentElement === parent) {
                parent.insertBefore(wrapper, previous.nextSibling);
                previous = wrapper;
            }
        });
    }

    function applyVisibility(show) {
        proWrappers().forEach(function (wrapper) {
            wrapper.style.display = show ? '' : 'none';
            wrapper.querySelectorAll('input, select, textarea').forEach(function (input) {
                if (show) {
                    if (input.dataset.sntIpRequired === '1') {
                        input.setAttribute('required', 'required');
                    }
                } else if (input.hasAttribute('required')) {
                    input.dataset.sntIpRequired = '1';
                    input.removeAttribute('required');
                }
            });
        });
        // Le titre « Mes informations de société » suit le bloc pro (masqué pour un
        // particulier). Le titre « personnelles » reste toujours affiché.
        var socTitle = document.getElementById('snt-ip-title-company');
        if (socTitle) { socTitle.style.display = show ? '' : 'none'; }
        if (show) {
            // Aiguillage FR / non-FR selon le pays choisi (gère aussi le SIRET).
            applyBranch();
        }
    }

    // Premier champ « personnel » (non pro, hors sélecteur de type de compte) dans
    // le conteneur du formulaire : sert d'ancre au titre « informations personnelles ».
    function firstPersonalWrapper(parent) {
        var toggle = toggleGroup();
        var proSet = proWrappers();
        for (var i = 0; i < parent.children.length; i++) {
            var c = parent.children[i];
            if (!c.classList || !c.classList.contains('form-group')) { continue; }
            if (c === toggle) { continue; }
            if (proSet.indexOf(c) !== -1) { continue; }
            return c;
        }
        return null;
    }

    // Crée (idempotent) un titre de section.
    function ensureSectionTitle(id, text) {
        var el = document.getElementById(id);
        if (el) { return el; }
        el = document.createElement('div');
        el.id = id;
        el.className = 'snt-ip-section-title';
        el.textContent = text;
        return el;
    }

    // Insère les deux titres de section :
    //  - « Mes informations de société » juste après le sélecteur de type de compte
    //    (donc en tête du bloc pro ; masqué avec lui pour un particulier) ;
    //  - « Mes informations personnelles » avant le premier champ personnel.
    // À appeler APRÈS reorderFields (les champs pro sont alors regroupés en tête).
    function setupSectionTitles() {
        var toggle = toggleGroup();
        if (!toggle || !toggle.parentElement) { return; }
        var parent = toggle.parentElement;

        var soc = ensureSectionTitle('snt-ip-title-company', 'Mes informations de société');
        if (soc.previousSibling !== toggle || soc.parentElement !== parent) {
            parent.insertBefore(soc, toggle.nextSibling);
        }

        var firstPerso = firstPersonalWrapper(parent);
        if (firstPerso) {
            var perso = ensureSectionTitle('snt-ip-title-personal', 'Mes informations personnelles');
            if (perso.nextSibling !== firstPerso || perso.parentElement !== firstPerso.parentElement) {
                firstPerso.parentElement.insertBefore(perso, firstPerso);
            }
        }
    }

    // ------------------------------------------------------------------
    // Aiguillage par pays : FR (SIREN/INSEE) vs autre UE (TVA/VIES)
    // ------------------------------------------------------------------

    function currentCountry() {
        var el = fieldByName('pro_country');
        return el ? String(el.value || 'FR').toUpperCase() : 'FR';
    }

    function isFrCountry() {
        return currentCountry() === 'FR';
    }

    function normalizeVat(v) {
        return String(v || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function setWrapperVisible(name, show) {
        var w = wrapperOf(fieldByName(name));
        if (w) { w.style.display = show ? '' : 'none'; }
    }

    // Masque/affiche la mention « (optionnel/facultatif) » que le thème ajoute au
    // libellé du n° de TVA : en non-FR la TVA est obligatoire, on la retire. On
    // cible l'élément-feuille dont le texte EST cette mention (pas un libellé qui
    // la contiendrait dans une phrase). NB : si le thème l'écrit en simple nœud
    // texte (non un élément), rien à masquer — le champ reste requis (HTML5).
    function setVatOptionalMentionHidden(hide) {
        var group = wrapperOf(fieldByName('vatNumber'));
        if (!group) { return; }
        var re = /^\(?\s*(optionnel|facultatif|optional)\s*\)?$/i;
        Array.prototype.forEach.call(group.querySelectorAll('*'), function (el) {
            if (el.children.length !== 0) { return; }
            var tag = el.tagName;
            if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') { return; }
            if (re.test((el.textContent || '').trim())) {
                el.style.display = hide ? 'none' : '';
            }
        });
    }

    // ------------------------------------------------------------------
    // Adresse de facturation électronique : raccourcis (barre de boutons)
    // ------------------------------------------------------------------
    // L'AFE n'est plus auto-remplie : le champ reste libre. On propose au-dessus
    // du champ une barre de 3 raccourcis (demande du service admin) :
    //   • « Utiliser mon SIREN »  → recopie le SIREN (9 chiffres du SIRET) ;
    //   • « Utiliser mon SIRET »  → recopie le SIRET complet (14 chiffres) ;
    //   • « Saisie manuelle »     → place le focus dans le champ pour saisie libre.

    // Renvoie le SIREN (9 premiers chiffres du SIRET) si disponible, sinon ''.
    function sirenFromSiret() {
        var siret = fieldByName('siret');
        var digits = String((siret && siret.value) || '').replace(/\D/g, '');
        return digits.length >= 9 ? digits.slice(0, 9) : '';
    }

    // Renvoie le SIRET complet (14 chiffres) si disponible, sinon ''.
    function siretDigits() {
        var siret = fieldByName('siret');
        var digits = String((siret && siret.value) || '').replace(/\D/g, '');
        return digits.length >= 14 ? digits.slice(0, 14) : '';
    }

    // Recopie une valeur dans l'AFE — action explicite du client (clic bouton).
    // Le champ reste ensuite librement éditable ; on marque `sntIpTouched` car
    // la valeur est désormais maîtrisée par le client. N'agit qu'en branche FR
    // pro hors administration (l'AFE est masquée dans ce cas).
    function applyEinvoicingValue(value) {
        if (!isProChecked() || !isFrCountry() || isAdministrationChecked()) { return; }
        var einv = fieldByName('einvoicing_address');
        if (!einv || !value) { return; }
        einv.value = value;
        einv.dataset.sntIpTouched = '1';
        // Émet `input` pour rejouer les validations éventuelles du thème.
        try { einv.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        einv.focus();
    }

    function fillEinvoicingWithSiren() { applyEinvoicingValue(sirenFromSiret()); }
    function fillEinvoicingWithSiret() { applyEinvoicingValue(siretDigits()); }

    // « Saisie manuelle » : ne remplit rien, place seulement le focus dans le
    // champ (et sélectionne son contenu pour un remplacement immédiat). Marque le
    // champ comme « modifié » pour préserver la saisie libre du client.
    function focusEinvoicingForManualEntry() {
        var einv = fieldByName('einvoicing_address');
        if (!einv) { return; }
        einv.dataset.sntIpTouched = '1';
        einv.focus();
        try { einv.select(); } catch (e) {}
    }

    // Construit (une seule fois) la barre de raccourcis AU-DESSUS du champ AFE,
    // dans SA colonne (largeur auto). Renvoie la barre.
    function ensureAfeToolbar() {
        var einv = fieldByName('einvoicing_address');
        var col = inlineContainerOf(einv);
        if (!col || !einv) { return null; }
        var existing = col.querySelector('.snt-ip-afe-tools');
        if (existing) { return existing; }

        var bar = document.createElement('div');
        bar.className = 'snt-ip-afe-tools';
        bar.appendChild(makeAfeButton('snt-ip-use-siren', 'Utiliser mon SIREN', fillEinvoicingWithSiren));
        bar.appendChild(makeAfeButton('snt-ip-use-siret', 'Utiliser mon SIRET', fillEinvoicingWithSiret));
        bar.appendChild(makeAfeButton('snt-ip-manual-entry', 'Saisie manuelle', focusEinvoicingForManualEntry));

        // Insère la barre juste AVANT le champ (demande du service admin).
        col.insertBefore(bar, einv);
        return bar;
    }

    function makeAfeButton(cls, label, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-secondary ' + cls;
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    // Active/désactive les boutons SIREN/SIRET selon la disponibilité du SIRET
    // saisi. « Saisie manuelle » reste toujours actif.
    function updateAfeToolbar() {
        var bar = ensureAfeToolbar();
        if (!bar) { return; }

        var siren = sirenFromSiret();
        var sirenBtn = bar.querySelector('.snt-ip-use-siren');
        if (sirenBtn) {
            sirenBtn.disabled = !siren;
            sirenBtn.title = siren
                ? 'Renseigner l\'AFE avec votre SIREN (' + siren + ').'
                : 'Renseignez d\'abord votre SIRET pour utiliser ce raccourci.';
        }

        var siret = siretDigits();
        var siretBtn = bar.querySelector('.snt-ip-use-siret');
        if (siretBtn) {
            siretBtn.disabled = !siret;
            siretBtn.title = siret
                ? 'Renseigner l\'AFE avec votre SIRET (' + siret + ').'
                : 'Renseignez d\'abord votre SIRET complet (14 chiffres) pour utiliser ce raccourci.';
        }
    }

    // ------------------------------------------------------------------
    // N° TVA intracom. FR : auto-remplissage depuis le SIRET
    // ------------------------------------------------------------------

    // Calcule le n° de TVA intracommunautaire français à partir du SIREN.
    // Structure : FR + clé (2 chiffres) + SIREN, avec
    //   clé = (12 + 3 × (SIREN mod 97)) mod 97.
    // Le SIREN à 9 chiffres tient dans un entier JS (< 2^53), le modulo est donc
    // exact. Renvoie '' si le SIREN n'a pas 9 chiffres.
    function frenchVatNumber(siren) {
        if (!/^\d{9}$/.test(siren)) { return ''; }
        var key = (12 + 3 * (parseInt(siren, 10) % 97)) % 97;
        var kk = (key < 10 ? '0' : '') + key;
        return 'FR' + kk + siren;
    }

    // Pré-remplit le n° de TVA avec le numéro calculé depuis le SIREN (9 premiers
    // chiffres du SIRET), tant que le client ne l'a pas modifié (drapeau
    // `sntIpTouched`). Le champ reste librement éditable, et VIES reste l'arbitre :
    // le numéro calculé est structurellement valide mais ne garantit pas que
    // l'entreprise soit assujettie (ex. franchise en base) — VIES le signalera.
    // N'agit qu'en branche FR pro (en non-FR, le client saisit son propre numéro).
    function syncVatFromSiret() {
        if (!isProChecked() || !isFrCountry()) { return; }
        var vat = fieldByName('vatNumber');
        if (!vat || vat.dataset.sntIpTouched === '1') { return; }
        var siret = fieldByName('siret');
        var digits = String((siret && siret.value) || '').replace(/\D/g, '');
        if (digits.length < 9) { return; }
        var full = frenchVatNumber(digits.slice(0, 9));
        if (full) { vat.value = full; }
    }

    // ------------------------------------------------------------------
    // Règle « administration » : le 3ᵉ choix du « Type de compte » (is_pro = '2')
    // exempte le client de l'adresse de facturation électronique (hors périmètre
    // de la réforme). Administration -> AFE masquée + non requise ; Professionnel
    // -> AFE requise.
    // ------------------------------------------------------------------

    function isAdministrationChecked() {
        var checked = document.querySelector('input[name="is_pro"]:checked');
        return !!(checked && String(checked.value) === '2');
    }

    // Applique l'état « administration » au champ AFE, en branche FR pro seulement.
    // Admin -> AFE masquée, non requise, vidée.
    // Sinon  -> AFE affichée + requise + auto-remplie depuis le SIRET.
    function applyEinvoicingAdminState() {
        if (!isProChecked() || !isFrCountry()) { return; }
        var einv = fieldByName('einvoicing_address');
        if (!einv) { return; }
        var wrapper = wrapperOf(einv);

        if (isAdministrationChecked()) {
            if (wrapper) { wrapper.style.display = 'none'; }
            einv.value = '';
            einv.removeAttribute('required');
            einv.dataset.sntIpRequired = '';
        } else {
            if (wrapper) { wrapper.style.display = ''; }
            einv.setAttribute('required', 'required');
            einv.dataset.sntIpRequired = '1';
            // Plus d'auto-remplissage : le champ reste libre. On (ré)affiche
            // seulement le bouton-raccourci et on ajuste son état.
            updateAfeToolbar();
        }
    }

    /**
     * Affiche/masque les sous-champs selon le pays :
     *  - FR       : SIRET (saisi + vérifié INSEE) + téléphone ;
     *  - autre UE : n° TVA (requis) + raison sociale (éditable), le reste masqué.
     */
    function applyBranch() {
        if (!isProChecked()) { return; }
        var fr = isFrCountry();

        // Champs propres à la branche FR.
        setWrapperVisible('phone', fr);

        // Téléphone : requis pour l'adresse siège FR, inutile en non-FR (pas
        // d'adresse auto). On pilote `required` (HTML5) selon la branche — sinon
        // un champ requis masqué bloque la soumission
        // (« invalid form control is not focusable »).
        var phone = fieldByName('phone');
        if (phone) {
            if (fr) {
                phone.setAttribute('required', 'required');
                phone.dataset.sntIpRequired = '1';
            } else {
                phone.removeAttribute('required');
                phone.dataset.sntIpRequired = '';
            }
        }

        // Adresse de facturation électronique : demandée uniquement en FR
        // (réforme française). Requise en FR, masquée + non requise sinon.
        setWrapperVisible('einvoicing_address', fr);
        var einv = fieldByName('einvoicing_address');
        if (einv) {
            if (fr) {
                einv.setAttribute('required', 'required');
                einv.dataset.sntIpRequired = '1';
            } else {
                einv.removeAttribute('required');
                einv.dataset.sntIpRequired = '';
            }
        }
        // Applique l'état administration (AFE masquée) ou professionnel (AFE
        // affichée + requise + auto-remplie depuis le SIRET).
        applyEinvoicingAdminState();

        // SIRET : point d'entrée du parcours FR. Deux modes (cf. ensureSearchUI) :
        // saisie directe du SIRET, ou recherche par raison sociale + code postal.
        // En non-FR, le groupe SIRET est entièrement masqué et non requis (sinon un
        // champ requis caché bloque la soumission).
        var siret = fieldByName('siret');
        var sw = wrapperOf(siret);
        if (fr) {
            if (sw) { sw.style.display = ''; }
            ensureSearchUI();
            applySiretMode();
        } else {
            if (sw) { sw.style.display = 'none'; }
            if (siret) {
                siret.removeAttribute('required');
                siret.dataset.sntIpRequired = '';
            }
            hideSiretPreview();
        }

        // TVA : requise en non-FR (n° obligatoire, mention « (optionnel) » retirée
        // du libellé), optionnelle en FR.
        var vat = fieldByName('vatNumber');
        if (vat) {
            if (fr) {
                vat.removeAttribute('required');
                vat.dataset.sntIpRequired = '';
            } else {
                vat.setAttribute('required', 'required');
                vat.dataset.sntIpRequired = '1';
            }
        }
        setVatOptionalMentionHidden(!fr);
        // En FR, pré-remplit le n° de TVA calculé si un SIRET est déjà saisi
        // (couvre le cas d'un passage tardif en branche FR / Professionnel).
        if (fr) { syncVatFromSiret(); }

        // Raison sociale : éditable (saisie manuelle) en non-FR ; readonly en FR.
        // Obligatoire pour tout pro dans les deux branches (renseignée par
        // l'INSEE/VIES ou saisie manuelle). applyBranch() ne s'exécute que
        // lorsque « Professionnel » est coché, donc on marque toujours requis.
        var comp = fieldByName('company');
        if (comp) {
            comp.setAttribute('required', 'required');
            comp.dataset.sntIpRequired = '1';
            if (fr) {
                applyCompanyReadonly();
            } else {
                comp.removeAttribute('readonly');
                comp.classList.remove('snt-ip-readonly');
            }
        }

        setStatus('', '');
    }

    // Blur du n° TVA (branche non-FR) -> vérification VIES.
    function onVatBlur() {
        if (!isProChecked() || isFrCountry() || !CFG.viesEnable) { return; }
        var input = fieldByName('vatNumber');
        if (!input) { return; }
        var vat = normalizeVat(input.value);
        if (vat === '') { setStatus('', ''); return; }
        input.value = vat;
        if (!CFG.validateUrl) { setStatus('pending', 'Vérification VIES non configurée.'); return; }

        setStatus('pending', 'Vérification VIES…');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.validateUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onload = function () {
            var d = null;
            try { d = JSON.parse(xhr.responseText); } catch (e) {}
            handleViesResponse(d);
        };
        xhr.onerror = function () { setStatus('error', 'Erreur réseau.'); };
        xhr.send('vat=' + encodeURIComponent(vat) + '&country=' + encodeURIComponent(currentCountry()) + '&ajax=1');
    }

    function handleViesResponse(d) {
        switch (d && d.status) {
            case 'valid':
                setStatus('ok', '✓ ' + (d.name || 'Numéro de TVA valide'));
                if (d.name) { forceFill(fieldByName('company'), d.name); }
                break;
            case 'invalid':
                setStatus('error', '✗ Numéro de TVA non valide dans VIES.');
                break;
            case 'unavailable':
                setStatus('pending', 'Vérification VIES indisponible — votre compte sera vérifié par nos équipes.');
                break;
            case 'invalid_format':
                setStatus('error', 'Numéro de TVA au format invalide.');
                break;
            case 'rate_limited':
                setStatus('error', 'Trop de tentatives, merci de patienter quelques instants.');
                break;
            default:
                setStatus('error', 'Impossible de vérifier le numéro de TVA.');
        }
    }

    // ------------------------------------------------------------------
    // Validation locale (Luhn)
    // ------------------------------------------------------------------

    function normalize(v) {
        return String(v || '').replace(/\D+/g, '');
    }

    function luhnCheck(digits) {
        var sum = 0;
        var n = digits.length;
        for (var i = 0; i < n; i++) {
            var d = parseInt(digits.charAt(n - 1 - i), 10);
            if (i % 2 === 1) {
                d *= 2;
                if (d > 9) d -= 9;
            }
            sum += d;
        }
        return sum % 10 === 0;
    }

    function isValidSiretLocal(siret) {
        if (siret.length !== 14) return false;
        var siren = siret.substring(0, 9);
        if (LUHN_EXCEPTIONS_SIREN.indexOf(siren) !== -1) {
            return (parseInt(siret, 10) % 5) === 0;
        }
        return luhnCheck(siret);
    }

    // ------------------------------------------------------------------
    // Statut (sous le champ SIRET)
    // ------------------------------------------------------------------

    function ensureStatusEl() {
        // Hôte du statut : le SIRET en branche FR, le n° TVA en branche non-FR.
        var host = isFrCountry() ? fieldByName('siret') : fieldByName('vatNumber');
        if (!host) { host = fieldByName('siret') || fieldByName('vatNumber'); }
        if (!host) return null;
        var container = inlineContainerOf(host);
        if (!container) return null;
        var el = container.querySelector('.snt-ip-status');
        if (!el) {
            el = document.createElement('span');
            el.className = 'snt-ip-status';
            container.appendChild(el);
        }
        return el;
    }

    function setStatus(kind, message) {
        var el = ensureStatusEl();
        if (!el) return;
        el.className = 'snt-ip-status snt-ip-status--' + kind;
        el.textContent = message || '';
    }

    function fillIfEmpty(input, value) {
        if (!input || !value) return;
        if (String(input.value || '').trim() === '') {
            input.value = value;
        }
    }

    function forceFill(input, value) {
        if (input && value !== undefined && value !== null) {
            input.value = value;
        }
    }

    // ------------------------------------------------------------------
    // Aperçu établissement (sous le champ SIRET)
    // ------------------------------------------------------------------
    // Bloc informatif (lecture seule) affiché sous le SIRET : raison sociale +
    // adresse de l'établissement renvoyées par l'INSEE. Il confirme visuellement
    // que le SIRET saisi désigne le bon établissement. L'adresse n'est pas
    // éditable ici : elle est créée d'office comme adresse « Siège social » à
    // l'inscription (cf. maybeCreateInseeAddress côté serveur).

    function ensureSiretPreview() {
        var siret = fieldByName('siret');
        if (!siret) return null;
        // Rattaché à la colonne du champ SIRET (et non à la ligne .form-group) pour
        // s'aligner sur la largeur des autres champs. Reste visible en mode
        // « recherche » : on n'y masque que l'input SIRET, pas sa colonne.
        var container = inlineContainerOf(siret);
        if (!container) return null;
        var block = container.querySelector('.snt-ip-siret-preview');
        if (block) return block;
        block = document.createElement('div');
        block.className = 'snt-ip-siret-preview';
        block.style.display = 'none';
        container.appendChild(block);
        return block;
    }

    function formatAddress(addr) {
        if (!addr) return '';
        var lines = [];
        if (addr.address1) lines.push(addr.address1);
        if (addr.address2) lines.push(addr.address2);
        var cp = [addr.postcode, addr.city].filter(Boolean).join(' ');
        if (cp) lines.push(cp);
        return lines.join('\n');
    }

    function showSiretPreview(company, addr, closed) {
        var block = ensureSiretPreview();
        if (!block) return;
        block.innerHTML = '';

        var name = document.createElement('div');
        name.className = 'snt-ip-siret-preview-name';
        name.textContent = (closed ? '⚠ ' : '✓ ') + (company || 'Établissement trouvé');
        block.appendChild(name);

        var addrText = formatAddress(addr);
        if (addrText) {
            var a = document.createElement('div');
            a.className = 'snt-ip-siret-preview-address';
            // Adresse multi-lignes : on rend les retours à la ligne visibles.
            a.style.whiteSpace = 'pre-line';
            a.textContent = addrText;
            block.appendChild(a);
        }
        if (closed) {
            var warn = document.createElement('div');
            warn.className = 'snt-ip-siret-preview-warn';
            warn.textContent = 'Établissement fermé à l\'INSEE.';
            block.appendChild(warn);
        }
        block.style.display = '';
    }

    function hideSiretPreview() {
        var block = ensureSiretPreview();
        if (block) { block.style.display = 'none'; block.innerHTML = ''; }
    }

    // Reporte la raison sociale INSEE dans le champ `company` (autoritative si
    // l'édition libre est interdite, à défaut on ne remplit que si vide).
    function applySiretCompany(company) {
        if (!company) return;
        var companyInput = fieldByName('company');
        if (CFG.companyEditable) {
            fillIfEmpty(companyInput, company);
        } else {
            forceFill(companyInput, company);
        }
    }

    // ------------------------------------------------------------------
    // Fallback saisie manuelle (INSEE indisponible)
    // ------------------------------------------------------------------

    function enterManualMode() {
        hideSiretPreview();
        unlockCompanyForManual();
        // SIRET localement valide mais non confirmé par l'INSEE : on laisse le
        // champ éditable et requis ; la raison sociale devient saisissable.
        var siretInput = fieldByName('siret');
        if (siretInput) {
            siretInput.removeAttribute('readonly');
            siretInput.setAttribute('required', 'required');
            siretInput.dataset.sntIpRequired = '1';
        }
    }

    function unlockCompanyForManual() {
        var input = fieldByName('company');
        if (!input) return;
        input.removeAttribute('readonly');
        input.classList.remove('snt-ip-readonly');
        input.classList.add('snt-ip-manual');
    }

    // ------------------------------------------------------------------
    // Appel AJAX (SIRET → établissement INSEE)
    // ------------------------------------------------------------------

    function handleSiretResponse(data) {
        switch (data && data.status) {
            case 'found':
                applySiretCompany(data.company);
                showSiretPreview(data.company, data.address, !!data.closed);
                // L'aperçu porte déjà l'info : on garde la ligne de statut sobre
                // (un simple avertissement si l'établissement est fermé).
                setStatus(data.closed ? 'pending' : 'ok',
                    data.closed ? 'Établissement fermé à l\'INSEE — vérifiez le SIRET.' : '');
                break;

            case 'not_found':
                hideSiretPreview();
                setStatus('error', 'SIRET inconnu à l\'INSEE.');
                break;

            case 'degraded':
            case 'unavailable':
                setStatus('pending', 'Vérification INSEE indisponible — saisissez votre raison sociale, elle sera vérifiée par nos équipes.');
                enterManualMode();
                break;

            case 'invalid_format':
                hideSiretPreview();
                setStatus('error', 'SIRET invalide.');
                break;

            case 'rate_limited':
                setStatus('error', 'Trop de tentatives, merci de patienter quelques instants.');
                break;

            default:
                setStatus('error', 'Impossible de vérifier le SIRET.');
        }
    }

    function callSiretLookup(siret) {
        // Mémorise l'appel en cours/effectué pour cette valeur : toute
        // répétition du blur sur le même SIRET sera ignorée (cf. onSiretBlur).
        lastSearchedSiret = siret;
        if (!CFG.validateUrl) {
            setStatus('pending', 'SIRET localement valide (INSEE non configuré) — saisissez votre raison sociale.');
            enterManualMode();
            return;
        }
        setStatus('pending', 'Vérification du SIRET…');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.validateUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onload = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) {}
            if (!data) {
                setStatus('error', 'Réponse serveur invalide.');
                return;
            }
            handleSiretResponse(data);
        };
        xhr.onerror = function () { setStatus('error', 'Erreur réseau.'); };
        xhr.send('siret=' + encodeURIComponent(siret) + '&ajax=1');
    }

    // Blur du SIRET (branche FR) : validation locale (Luhn) puis vérification
    // INSEE (raison sociale + adresse d'aperçu). La re-vérif serveur au submit
    // fait foi et crée l'adresse siège.
    function onSiretBlur() {
        if (!isProChecked() || !isFrCountry()) { return; }
        var input = fieldByName('siret');
        if (!input) return;
        var siret = normalize(input.value);
        if (siret === '') {
            setStatus('', '');
            hideSiretPreview();
            return;
        }
        if (!isValidSiretLocal(siret)) {
            hideSiretPreview();
            setStatus('error', 'SIRET invalide (14 chiffres, clé Luhn).');
            return;
        }
        input.value = siret;
        // L'AFE n'est plus auto-remplie : on active seulement le bouton-raccourci
        // « Utiliser mon SIREN ». Le n° de TVA intracom. reste, lui, calculé et
        // pré-rempli (modifiable ensuite par le client).
        updateAfeToolbar();
        syncVatFromSiret();
        if (siret === lastSearchedSiret) {
            // Déjà interrogé pour cette valeur : pas de nouvel appel INSEE.
            return;
        }
        callSiretLookup(siret);
    }

    // ------------------------------------------------------------------
    // Recherche d'établissement par raison sociale + code postal
    // ------------------------------------------------------------------
    // Alternative à la saisie directe du SIRET : le client cherche par nom + code
    // postal, choisit son établissement dans une liste, et la sélection renseigne
    // le SIRET (source de vérité) puis déclenche l'aperçu et les auto-remplissages
    // habituels (raison sociale, TVA, AFE). Le SIRET reste re-vérifié au submit.

    var searchData = []; // derniers établissements reçus (pour la sélection)

    function siretMode() {
        var checked = document.querySelector('input[name="snt_ip_siret_mode"]:checked');
        return checked ? checked.value : 'siret';
    }

    function findBySiret(list, siret) {
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].siret) === String(siret)) { return list[i]; }
        }
        return null;
    }

    // Construit (une seule fois) le sélecteur de mode + le bloc de recherche.
    // Insérés dans LA COLONNE du champ SIRET (et non dans la ligne .form-group)
    // pour s'aligner sur la largeur des autres champs — les thèmes disposent la
    // ligne en grille (libellé | champ), un enfant direct de la ligne s'étirerait
    // sur toute la largeur. Renvoie le bloc de recherche.
    function ensureSearchUI() {
        var siretInput = fieldByName('siret');
        var col = inlineContainerOf(siretInput);
        if (!col) { return null; }
        var existing = col.querySelector('.snt-ip-rs-search');
        if (existing) { return existing; }

        // -- Sélecteur de mode (2 boutons radio) --
        var modes = document.createElement('div');
        modes.className = 'snt-ip-siret-mode';
        modes.innerHTML =
            '<label class="snt-ip-siret-mode-opt">' +
              '<input type="radio" name="snt_ip_siret_mode" value="siret" checked> ' +
              'Je connais mon SIRET' +
            '</label>' +
            '<label class="snt-ip-siret-mode-opt">' +
              '<input type="radio" name="snt_ip_siret_mode" value="search"> ' +
              'Rechercher par raison sociale + code postal' +
            '</label>';

        // -- Bloc de recherche --
        var block = document.createElement('div');
        block.className = 'snt-ip-rs-search';
        block.style.display = 'none';

        var rs = document.createElement('input');
        rs.type = 'text';
        rs.className = 'form-control snt-ip-rs-name';
        rs.placeholder = 'Raison sociale';

        var cp = document.createElement('input');
        cp.type = 'text';
        cp.className = 'form-control snt-ip-rs-cp';
        cp.placeholder = 'Code postal';
        cp.setAttribute('inputmode', 'numeric');
        cp.maxLength = 5;
        cp.addEventListener('input', function () {
            cp.value = cp.value.replace(/\D+/g, '').slice(0, 5);
        });
        // Entrée dans un champ -> lance la recherche.
        function onEnter(e) { if (e.key === 'Enter') { e.preventDefault(); onNameSearchClick(); } }
        rs.addEventListener('keydown', onEnter);
        cp.addEventListener('keydown', onEnter);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-secondary snt-ip-rs-btn';
        btn.textContent = 'Rechercher';
        btn.addEventListener('click', onNameSearchClick);

        var results = document.createElement('select');
        results.className = 'form-control snt-ip-rs-results';
        results.style.display = 'none';
        results.addEventListener('change', function () {
            selectEstablishment(findBySiret(searchData, results.value));
        });

        block.appendChild(rs);
        block.appendChild(cp);
        block.appendChild(btn);
        block.appendChild(results);

        // Insertion dans la colonne, juste avant l'input SIRET : le sélecteur de
        // mode d'abord, puis le bloc de recherche, puis l'input (et l'aperçu).
        col.insertBefore(block, siretInput);
        col.insertBefore(modes, block);

        Array.prototype.forEach.call(
            modes.querySelectorAll('input[name="snt_ip_siret_mode"]'),
            function (r) {
                r.addEventListener('change', function () {
                    hideSiretPreview();
                    setStatus('', '');
                    applySiretMode();
                });
            }
        );

        return block;
    }

    // Applique le mode courant (SIRET direct vs recherche) : visibilité de l'input
    // SIRET / du bloc de recherche + gestion du `required`. FR pro uniquement.
    // La colonne du champ et son libellé restent visibles : ils portent le
    // sélecteur de mode, le bloc de recherche et l'aperçu.
    function applySiretMode() {
        if (!isProChecked() || !isFrCountry()) { return; }
        var block = ensureSearchUI();
        var searching = (siretMode() === 'search');
        var siret = fieldByName('siret');

        if (block) { block.style.display = searching ? '' : 'none'; }

        if (siret) {
            // En mode recherche, on masque uniquement l'input SIRET (renseigné par
            // la sélection d'un établissement).
            siret.style.display = searching ? 'none' : '';
            if (searching) {
                siret.setAttribute('readonly', 'readonly');
                // Requis uniquement si un établissement a déjà été choisi : un champ
                // requis vide ET masqué bloquerait la soumission (non focusable). Le
                // serveur exige le SIRET dans tous les cas (validateFrenchPro).
                if (normalize(siret.value) === '') {
                    siret.removeAttribute('required');
                    siret.dataset.sntIpRequired = '';
                } else {
                    siret.setAttribute('required', 'required');
                    siret.dataset.sntIpRequired = '1';
                }
            } else {
                siret.removeAttribute('readonly');
                siret.setAttribute('required', 'required');
                siret.dataset.sntIpRequired = '1';
            }
        }
    }

    // Libellé d'un établissement dans le <select> de résultats.
    function establishmentLabel(it) {
        var siret = String(it.siret || '');
        var pretty = siret.replace(/(\d{3})(\d{3})(\d{3})(\d{5})/, '$1 $2 $3 $4');
        var parts = [pretty];
        if (it.company) { parts.push(it.company); }
        var addr = [it.address1, [it.postcode, it.city].filter(Boolean).join(' ')]
            .filter(Boolean).join(', ');
        if (addr) { parts.push(addr); }
        var label = parts.join(' — ');
        if (it.siege) { label += ' (siège)'; }
        if (it.closed) { label += ' (fermé)'; }
        return label;
    }

    function renderSearchResults(list) {
        searchData = list || [];
        var block = ensureSearchUI();
        var select = block ? block.querySelector('.snt-ip-rs-results') : null;
        if (!select) { return; }
        select.innerHTML = '';
        if (!searchData.length) { select.style.display = 'none'; return; }

        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = '— Choisissez votre établissement —';
        select.appendChild(ph);

        searchData.forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = String(it.siret || '');
            opt.textContent = establishmentLabel(it);
            select.appendChild(opt);
        });
        select.value = '';
        select.style.display = '';
    }

    // Sélection d'un établissement : renseigne le SIRET (autoritatif) et déclenche
    // l'aperçu + le calcul TVA, sans nouvel appel INSEE (données déjà là). L'AFE
    // n'est plus auto-remplie ; on rafraîchit seulement le raccourci SIREN.
    function selectEstablishment(item) {
        if (!item) { return; }
        var siret = fieldByName('siret');
        if (siret) {
            siret.value = String(item.siret || '');
            siret.setAttribute('required', 'required');
            siret.dataset.sntIpRequired = '1';
            // Évite un re-lookup au blur si l'input SIRET reçoit le focus.
            lastSearchedSiret = normalize(siret.value);
        }
        applySiretCompany(item.company);
        showSiretPreview(item.company, {
            address1: item.address1,
            address2: item.address2,
            postcode: item.postcode,
            city: item.city
        }, !!item.closed);
        updateAfeToolbar();
        syncVatFromSiret();
        setStatus(item.closed ? 'pending' : 'ok',
            item.closed ? 'Établissement fermé à l\'INSEE — vérifiez votre choix.' : '');
    }

    function handleNameSearchResponse(data) {
        switch (data && data.status) {
            case 'list':
                renderSearchResults(data.establishments || []);
                var n = data.total || (data.establishments || []).length;
                if (n === 0) {
                    setStatus('error', 'Aucun établissement trouvé.');
                } else {
                    setStatus('ok', n + ' établissement(s) trouvé(s)'
                        + (data.truncated ? ' (liste tronquée)' : '')
                        + ' — choisissez le vôtre.');
                }
                break;

            case 'not_found':
                renderSearchResults([]);
                setStatus('error', 'Aucun établissement trouvé pour cette raison sociale et ce code postal.');
                break;

            case 'invalid_format':
                setStatus('error', 'Renseignez une raison sociale et un code postal (5 chiffres).');
                break;

            case 'rate_limited':
                setStatus('error', 'Trop de tentatives, merci de patienter quelques instants.');
                break;

            case 'unavailable':
            default:
                setStatus('pending', 'Recherche INSEE indisponible — saisissez votre SIRET directement.');
        }
    }

    function onNameSearchClick() {
        var block = ensureSearchUI();
        if (!block) { return; }
        var rs = block.querySelector('.snt-ip-rs-name');
        var cp = block.querySelector('.snt-ip-rs-cp');
        var name = String((rs && rs.value) || '').trim();
        var post = String((cp && cp.value) || '').replace(/\D+/g, '');
        if (name.length < 2 || post.length !== 5) {
            setStatus('error', 'Renseignez une raison sociale (2 caractères min.) et un code postal (5 chiffres).');
            return;
        }
        if (!CFG.validateUrl) {
            setStatus('pending', 'Recherche INSEE non configurée — saisissez votre SIRET.');
            return;
        }
        setStatus('pending', 'Recherche en cours…');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.validateUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onload = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) {}
            if (!data) { setStatus('error', 'Réponse serveur invalide.'); return; }
            handleNameSearchResponse(data);
        };
        xhr.onerror = function () { setStatus('error', 'Erreur réseau.'); };
        xhr.send('rs=' + encodeURIComponent(name) + '&cp=' + encodeURIComponent(post) + '&ajax=1');
    }

    // ------------------------------------------------------------------
    // Company readonly quand non-éditable
    // ------------------------------------------------------------------

    function applyCompanyReadonly() {
        if (CFG.companyEditable) return;
        var input = fieldByName('company');
        if (!input) return;
        input.setAttribute('readonly', 'readonly');
        input.classList.add('snt-ip-readonly');
    }

    // ------------------------------------------------------------------
    // Info-bulles (pastille « ? » à gauche de certains libellés)
    // ------------------------------------------------------------------

    // Insère une pastille « ? » (fond bleu clair) au début du libellé du champ
    // `name`, avec une bulle affichée au survol/focus. Le texte de la bulle est
    // fourni via `text` (laissé vide par défaut : la bulle reste masquée tant
    // qu'aucun texte n'est renseigné — cf. `.snt-ip-tooltip-popup:empty` en CSS).
    // Idempotent : n'ajoute rien si la pastille est déjà présente.
    function addFieldTooltip(name, text) {
        var wrapper = wrapperOf(fieldByName(name));
        if (!wrapper) { return; }
        var label = wrapper.querySelector('label');
        if (!label || label.querySelector('.snt-ip-tooltip')) { return; }

        var tip = document.createElement('span');
        tip.className = 'snt-ip-tooltip';

        var icon = document.createElement('span');
        icon.className = 'snt-ip-tooltip-icon';
        icon.textContent = '?';
        icon.setAttribute('tabindex', '0');
        icon.setAttribute('role', 'button');
        icon.setAttribute('aria-label', 'Aide');

        var popup = document.createElement('span');
        popup.className = 'snt-ip-tooltip-popup';
        popup.setAttribute('role', 'tooltip');
        popup.textContent = text || '';

        tip.appendChild(icon);
        tip.appendChild(popup);
        label.insertBefore(tip, label.firstChild);
    }

    // ------------------------------------------------------------------
    // Bootstrap
    // ------------------------------------------------------------------

    ready(function () {
        var radios = document.querySelectorAll('input[name="is_pro"]');
        if (!radios.length) return;

        removeDuplicateFields();
        reorderFields();
        setupSectionTitles();
        applyCompanyReadonly();

        // Info-bulles « ? » à gauche des libellés (texte à renseigner : passer le
        // contenu en 2ᵉ argument, ex. addFieldTooltip('accounting_email', '…')).
        addFieldTooltip('accounting_email', 'Facultatif, ce champ permet à notre système d\'envoyer également les factures à cette adresse mail.');
        addFieldTooltip('einvoicing_address', 'Suite à la réforme de la facturation électronique, il est désormais nécessaire de recueillir l\'adresse de facturation électronique.');

        // Le SIRET est le point d'entrée du parcours FR : sa visibilité et son
        // caractère requis sont pilotés par applyBranch (via applyVisibility).
        applyVisibility(isProChecked());

        radios.forEach(function (r) {
            r.addEventListener('change', function () { applyVisibility(isProChecked()); });
        });

        var siretInput = fieldByName('siret');
        if (siretInput) {
            siretInput.addEventListener('blur', onSiretBlur);
            // Toute édition du SIRET réarme la recherche (permet un nouvel essai,
            // ex. après un rate-limit, ou pour corriger une saisie) et masque
            // l'aperçu tant que le nouveau SIRET n'est pas revérifié.
            siretInput.addEventListener('input', function () {
                lastSearchedSiret = '';
                hideSiretPreview();
                // Active/désactive le raccourci « Utiliser mon SIREN » au fil de
                // la saisie (dispo dès 9 chiffres).
                updateAfeToolbar();
            });
        }

        // Sélecteur pays : bascule la branche FR / non-FR.
        var countryEl = fieldByName('pro_country');
        if (countryEl) {
            countryEl.addEventListener('change', function () { applyBranch(); });
        }

        // Blur du n° TVA : vérification VIES (branche non-FR).
        var vatInput = fieldByName('vatNumber');
        if (vatInput) {
            vatInput.addEventListener('blur', onVatBlur);
        }

        // Adresse de facturation électronique : champ libre. Elle n'est plus
        // auto-remplie ; le client la saisit, ou utilise la barre de raccourcis
        // au-dessus du champ (cf. ensureAfeToolbar). Toute saisie manuelle pose le
        // drapeau `sntIpTouched`. Une valeur déjà présente au chargement (édition
        // d'un compte) est d'emblée considérée « modifiée ».
        var einvInput = fieldByName('einvoicing_address');
        if (einvInput) {
            if (String(einvInput.value || '').trim() !== '') {
                einvInput.dataset.sntIpTouched = '1';
            }
            einvInput.addEventListener('input', function () {
                einvInput.dataset.sntIpTouched = '1';
            });
        }

        // N° de TVA intracom. : même logique que l'AFE — auto-rempli (calcul FR)
        // depuis le SIRET tant que le client n'y a pas touché ; une valeur déjà
        // présente au chargement (édition, ou saisie non-FR) est préservée.
        var vatFieldInput = fieldByName('vatNumber');
        if (vatFieldInput) {
            if (String(vatFieldInput.value || '').trim() !== '') {
                vatFieldInput.dataset.sntIpTouched = '1';
            }
            vatFieldInput.addEventListener('input', function () {
                vatFieldInput.dataset.sntIpTouched = '1';
            });
        }

        // Le passage Professionnel ↔ Administration (radio « Type de compte ») est
        // capté par le listener `change` des radios `is_pro` ci-dessus, qui relance
        // applyVisibility -> applyBranch -> applyEinvoicingAdminState (masque/affiche
        // l'AFE et ajuste son caractère requis). Aucun listener dédié nécessaire.
    });

    // ------------------------------------------------------------------
    // Garde « caractères spéciaux » (ticket 12063)
    // ------------------------------------------------------------------
    // Bloque la soumission si un champ contient des caractères non autorisés.
    // Repris de l'ancien script du thème (customer-form.tpl), mais adapté au
    // module :
    //  - regex corrigées par champ (le SIRET est NUMÉRIQUE — l'ancienne regex
    //    interdisait les chiffres et bloquait donc tous les pros) ;
    //  - on IGNORE les champs `readonly`/`disabled`/masqués : leur valeur est
    //    maîtrisée par le module (raison sociale INSEE, SIRET Luhn) et non
    //    librement saisie ; un champ non éditable en erreur bloquerait la
    //    soumission sans recours ;
    //  - aucune dépendance à des id du thème (#submitBtn / #chars-alert) : on
    //    s'appuie sur la validation HTML5 native (setCustomValidity) + un
    //    message injecté par le module, et on revalide au submit (capte les
    //    remplissages programmatiques qui n'émettent pas d'événement `input`).
    var CHAR_RULES = {
        firstname: /^[a-zA-ZÀ-ÿ\s'\-]*$/,
        lastname:  /^[a-zA-ZÀ-ÿ\s'\-]*$/,
        email:     /^[a-zA-Z0-9._%+\-@]*$/,
        // Raison sociale : jeu élargi (ce que l'INSEE peut renvoyer en saisie
        // manuelle) — lettres/chiffres/accents + espaces ' - . & , ( ) /.
        company:   /^[a-zA-Z0-9À-ÿ\s'\-.&,()\/]*$/,
        // SIRET : chiffres et espaces uniquement.
        siret:     /^[0-9\s]*$/
    };
    var CHAR_MSG = 'Ce champ contient des caractères non autorisés.';

    function guardedFields() {
        var out = [];
        Object.keys(CHAR_RULES).forEach(function (name) {
            var el = fieldByName(name);
            if (el) { out.push({ name: name, el: el }); }
        });
        return out;
    }

    // Un champ non éditable (lecture seule / désactivé) ou masqué n'est pas
    // soumis à la saisie de l'utilisateur : on ne le contrôle pas.
    function isGuardable(el) {
        return !el.readOnly && !el.disabled && el.offsetParent !== null;
    }

    function ensureCharAlert(form) {
        var el = document.getElementById('snt-ip-chars-alert');
        if (el) { return el; }
        el = document.createElement('div');
        el.id = 'snt-ip-chars-alert';
        el.className = 'snt-ip-chars-alert';
        el.setAttribute('role', 'alert');
        el.style.display = 'none';
        el.textContent = CHAR_MSG;
        form.insertBefore(el, form.firstChild);
        return el;
    }

    function validateChars(form) {
        var hasError = false;
        guardedFields().forEach(function (f) {
            var el = f.el;
            if (!isGuardable(el)) {
                el.classList.remove('snt-ip-charerror');
                el.setCustomValidity('');
                return;
            }
            if (CHAR_RULES[f.name].test(el.value || '')) {
                el.classList.remove('snt-ip-charerror');
                el.setCustomValidity('');
            } else {
                hasError = true;
                el.classList.add('snt-ip-charerror');
                el.setCustomValidity(CHAR_MSG);
            }
        });
        var alert = ensureCharAlert(form);
        alert.style.display = hasError ? 'block' : 'none';
        return !hasError;
    }

    ready(function () {
        var fields = guardedFields();
        if (!fields.length) { return; }
        var form = fields[0].el.form || fields[0].el.closest('form');
        if (!form) { return; }

        fields.forEach(function (f) {
            f.el.addEventListener('input', function () { validateChars(form); });
            f.el.addEventListener('blur', function () { validateChars(form); });
        });

        // Filet au submit : revalide même les valeurs posées programmatiquement
        // (INSEE/VIES) qui n'ont pas déclenché d'événement `input`.
        form.addEventListener('submit', function (e) {
            if (!validateChars(form)) {
                e.preventDefault();
                var firstBad = form.querySelector('.snt-ip-charerror');
                if (firstBad) { firstBad.focus(); }
            }
        });

        validateChars(form);
    });
})();
