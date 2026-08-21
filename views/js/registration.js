/**
 * SNT Inscription Pro — front logic.
 *  - Réordonne le DOM : is_pro + champs pro tout en haut du bloc formulaire.
 *  - Toggle show/hide selon `is_pro` + gestion `required` dynamique.
 *  - Parcours SIREN : saisie du SIREN → appel AJAX INSEE → <select> des
 *    établissements → la sélection renseigne le champ `siret` (autoritatif),
 *    la raison sociale et le n° de TVA.
 *  - Fallback saisie manuelle du SIRET quand l'INSEE est indisponible.
 *  - Rend `company` readonly quand SNT_IP_COMPANY_EDITABLE = false.
 */
(function () {
    'use strict';

    if (window.console && window.console.debug) {
        window.console.debug('[SNT-IP] registration.js loaded');
    }

    // `siren` (nouveau) + `siret` (autoritatif, alimenté par la sélection) +
    // company/vatNumber + phone (création only) + accounting_email.
    var PRO_FIELDS = ['siren', 'siret', 'company', 'vatNumber', 'phone', 'accounting_email'];

    var CFG = {
        validateUrl:     window.SNT_IP_VALIDATE_URL || '',
        companyEditable: !!window.SNT_IP_COMPANY_EDITABLE,
        siretRequired:   !!window.SNT_IP_SIRET_REQUIRED,
    };

    var LUHN_EXCEPTIONS_SIREN = ['356000000']; // La Poste

    // Au-delà de ce nombre d'établissements, on affiche un champ de recherche
    // pour filtrer le <select> (SIREN à établissements très nombreux).
    var FILTER_THRESHOLD = 50;

    // Modes d'affichage du champ SIRET :
    //  - 'hidden'   : masqué, renseigné par la sélection d'établissement (création).
    //  - 'readonly' : visible en lecture seule (édition : montre l'établissement courant).
    //  - 'manual'   : visible et éditable (fallback INSEE indisponible).
    var siretMode = 'hidden';

    // Dernière liste d'établissements reçue (pour le filtrage).
    var establishmentsData = [];

    // Dernier SIREN pour lequel un appel a déjà été lancé : évite les appels
    // INSEE redondants si le `blur` se redéclenche sur la même valeur (focus qui
    // rebondit, autofill, réordonnancement DOM). Réinitialisé dès que le champ
    // SIREN est réédité (listener `input`).
    var lastSearchedSiren = '';

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

    function isProChecked() {
        var checked = document.querySelector('input[name="is_pro"]:checked');
        return !!(checked && String(checked.value) === '1');
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
        if (show) {
            // Le SIRET a une visibilité propre (piloté par la sélection).
            syncSiretVisibility();
        }
    }

    // ------------------------------------------------------------------
    // Gestion du champ SIRET (hidden / readonly / manual)
    // ------------------------------------------------------------------

    function setSiretMode(mode) {
        siretMode = mode;
        syncSiretVisibility();
    }

    function syncSiretVisibility() {
        var input = fieldByName('siret');
        var wrapper = wrapperOf(input);
        if (!input || !wrapper) return;

        if (!isProChecked()) {
            // Laissé à applyVisibility (masqué).
            return;
        }

        if (siretMode === 'hidden') {
            wrapper.style.display = 'none';
            input.setAttribute('readonly', 'readonly');
        } else if (siretMode === 'readonly') {
            wrapper.style.display = '';
            input.setAttribute('readonly', 'readonly');
        } else { // manual
            wrapper.style.display = '';
            input.removeAttribute('readonly');
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

    function isValidSirenLocal(siren) {
        if (siren.length !== 9) return false;
        if (LUHN_EXCEPTIONS_SIREN.indexOf(siren) !== -1) return true;
        return luhnCheck(siren);
    }

    function isValidSiretLocal(siret) {
        if (siret.length !== 14) return false;
        var siren = siret.substring(0, 9);
        if (LUHN_EXCEPTIONS_SIREN.indexOf(siren) !== -1) {
            return (parseInt(siret, 10) % 5) === 0;
        }
        return luhnCheck(siret);
    }

    function computeVatFrFromSiren(siren) {
        var s = parseInt(siren.substring(0, 9), 10);
        if (isNaN(s)) return '';
        var key = (12 + 3 * (s % 97)) % 97;
        var padded = key < 10 ? '0' + key : String(key);
        return 'FR' + padded + siren.substring(0, 9);
    }

    // ------------------------------------------------------------------
    // Statut (sous le champ SIREN)
    // ------------------------------------------------------------------

    function ensureStatusEl() {
        var sirenInput = fieldByName('siren');
        if (!sirenInput) return null;
        var container = inlineContainerOf(sirenInput);
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
    // <select> des établissements
    // ------------------------------------------------------------------

    function ensureEtabUI() {
        var sirenInput = fieldByName('siren');
        if (!sirenInput) return null;
        var container = inlineContainerOf(sirenInput);
        if (!container) return null;

        var block = container.querySelector('.snt-ip-etab');
        if (block) return block;

        block = document.createElement('div');
        block.className = 'snt-ip-etab';

        var filter = document.createElement('input');
        filter.type = 'text';
        filter.className = 'snt-ip-etab-filter form-control';
        filter.placeholder = 'Filtrer les établissements…';
        filter.style.display = 'none';
        filter.style.marginBottom = '6px';
        filter.addEventListener('input', function () {
            renderOptions(filterList(establishmentsData, filter.value));
        });

        var label = document.createElement('label');
        label.className = 'snt-ip-etab-label';
        label.textContent = 'Établissement';

        var select = document.createElement('select');
        select.className = 'snt-ip-etab-select form-control';
        select.addEventListener('change', function () {
            var item = findBySiret(establishmentsData, select.value);
            applyEstablishmentSelection(item);
        });

        block.appendChild(label);
        block.appendChild(filter);
        block.appendChild(select);
        container.appendChild(block);
        return block;
    }

    function getEtabSelect() {
        var block = ensureEtabUI();
        return block ? block.querySelector('.snt-ip-etab-select') : null;
    }

    function getEtabFilter() {
        var block = ensureEtabUI();
        return block ? block.querySelector('.snt-ip-etab-filter') : null;
    }

    function showEtabUI(show) {
        var block = ensureEtabUI();
        if (block) block.style.display = show ? '' : 'none';
    }

    function findBySiret(list, siret) {
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].siret) === String(siret)) return list[i];
        }
        return null;
    }

    function filterList(list, term) {
        term = String(term || '').toLowerCase().replace(/\s+/g, '');
        if (term === '') return list;
        return list.filter(function (it) {
            var hay = (String(it.siret || '') + ' ' + establishmentLabel(it)).toLowerCase().replace(/\s+/g, '');
            return hay.indexOf(term) !== -1;
        });
    }

    function establishmentAddress(it) {
        var parts = [];
        if (it.address1) parts.push(it.address1);
        var cp = [it.postcode, it.city].filter(Boolean).join(' ');
        if (cp) parts.push(cp);
        return parts.join(', ');
    }

    function establishmentLabel(it) {
        var siret = String(it.siret || '');
        var pretty = siret.replace(/(\d{3})(\d{3})(\d{3})(\d{5})/, '$1 $2 $3 $4');
        var label = pretty;
        var addr = establishmentAddress(it);
        if (addr) label += ' — ' + addr;
        if (it.siege) label += ' (siège)';
        if (it.closed) label += ' (fermé)';
        return label;
    }

    function renderOptions(list) {
        var select = getEtabSelect();
        if (!select) return;
        var previous = select.value;
        select.innerHTML = '';

        if (!list.length) {
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'Aucun établissement';
            select.appendChild(empty);
            return;
        }

        list.forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = String(it.siret || '');
            opt.textContent = establishmentLabel(it);
            select.appendChild(opt);
        });

        // Conserver la sélection précédente si toujours présente.
        if (previous && findBySiret(list, previous)) {
            select.value = previous;
        }
    }

    /**
     * Alimente le <select> puis pré-sélectionne le meilleur établissement :
     * le SIRET courant (édition) s'il est présent, sinon le premier (déjà trié
     * côté serveur : siège en tête).
     */
    function renderEstablishments(list) {
        establishmentsData = list || [];
        var filter = getEtabFilter();
        if (filter) {
            filter.value = '';
            filter.style.display = establishmentsData.length > FILTER_THRESHOLD ? '' : 'none';
        }
        renderOptions(establishmentsData);

        var select = getEtabSelect();
        if (!select) return;

        var currentSiret = normalize((fieldByName('siret') || {}).value || '');
        var preselect = (currentSiret && findBySiret(establishmentsData, currentSiret))
            ? currentSiret
            : (establishmentsData[0] ? establishmentsData[0].siret : '');

        if (preselect) {
            select.value = String(preselect);
            applyEstablishmentSelection(findBySiret(establishmentsData, preselect));
        }
        showEtabUI(true);
    }

    function applyEstablishmentSelection(item) {
        if (!item) return;
        var siretInput   = fieldByName('siret');
        var companyInput = fieldByName('company');
        var vatInput     = fieldByName('vatNumber');

        if (siretInput) {
            siretInput.value = String(item.siret || '');
        }

        if (item.company) {
            if (CFG.companyEditable) {
                fillIfEmpty(companyInput, item.company);
            } else {
                forceFill(companyInput, item.company);
            }
        }

        var vat = computeVatFrFromSiren(String(item.siret || '').substring(0, 9));
        fillIfEmpty(vatInput, vat);

        var msg = '✓ ' + (item.company || 'Établissement sélectionné');
        if (item.closed) msg += ' (établissement fermé à l\'INSEE)';
        var addr = establishmentAddress(item);
        if (addr) msg += ' — ' + addr;
        setStatus(item.closed ? 'pending' : 'ok', msg);
    }

    // ------------------------------------------------------------------
    // Fallback saisie manuelle (INSEE indisponible)
    // ------------------------------------------------------------------

    function enterManualMode(vat) {
        establishmentsData = [];
        showEtabUI(false);
        setSiretMode('manual');
        unlockCompanyForManual();
        fillIfEmpty(fieldByName('vatNumber'), vat || '');
        var siretInput = fieldByName('siret');
        if (siretInput) {
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
    // Appel AJAX (SIREN → établissements)
    // ------------------------------------------------------------------

    function handleSearchResponse(data, siren) {
        switch (data && data.status) {
            case 'ok':
                setStatus('ok', (data.company ? '✓ ' + data.company : '✓ Entreprise trouvée')
                    + ' — ' + (data.total || (data.establishments || []).length) + ' établissement(s)'
                    + (data.truncated ? ' (liste tronquée)' : ''));
                if (data.company) {
                    var companyInput = fieldByName('company');
                    if (CFG.companyEditable) {
                        fillIfEmpty(companyInput, data.company);
                    } else {
                        forceFill(companyInput, data.company);
                    }
                }
                fillIfEmpty(fieldByName('vatNumber'), data.vat || computeVatFrFromSiren(siren));
                setSiretMode('hidden');
                renderEstablishments(data.establishments || []);
                break;

            case 'not_found':
                showEtabUI(false);
                setStatus('error', 'SIREN inconnu à l\'INSEE.');
                break;

            case 'degraded':
            case 'unavailable':
                setStatus('pending', 'Vérification INSEE indisponible — saisissez votre SIRET et votre raison sociale, ils seront vérifiés par nos équipes.');
                enterManualMode(data.vat || computeVatFrFromSiren(siren));
                break;

            case 'invalid_format':
                showEtabUI(false);
                setStatus('error', 'SIREN invalide.');
                break;

            case 'rate_limited':
                setStatus('error', 'Trop de tentatives, merci de patienter quelques instants.');
                break;

            default:
                setStatus('error', 'Impossible de vérifier le SIREN.');
        }
    }

    function callSearch(siren) {
        // Mémorise l'appel en cours/effectué pour cette valeur : toute
        // répétition du blur sur le même SIREN sera ignorée (cf. onSirenBlur).
        lastSearchedSiren = siren;
        if (!CFG.validateUrl) {
            setStatus('pending', 'SIREN localement valide (INSEE non configuré) — saisissez votre SIRET.');
            enterManualMode(computeVatFrFromSiren(siren));
            return;
        }
        setStatus('pending', 'Recherche des établissements…');
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
            handleSearchResponse(data, siren);
        };
        xhr.onerror = function () { setStatus('error', 'Erreur réseau.'); };
        xhr.send('siren=' + encodeURIComponent(siren) + '&ajax=1');
    }

    function onSirenBlur() {
        var input = fieldByName('siren');
        if (!input) return;
        var siren = normalize(input.value);
        if (siren === '') {
            setStatus('', '');
            return;
        }
        if (!isValidSirenLocal(siren)) {
            setStatus('error', 'SIREN invalide (9 chiffres, clé Luhn).');
            return;
        }
        input.value = siren;
        if (siren === lastSearchedSiren) {
            // Déjà interrogé pour cette valeur : pas de nouvel appel INSEE.
            return;
        }
        callSearch(siren);
    }

    /**
     * Blur SIRET en mode fallback manuel : validation locale + calcul TVA, sans
     * appel INSEE (indisponible). La re-vérif serveur au submit fera foi.
     */
    function onSiretManualBlur() {
        if (siretMode !== 'manual') return;
        var input = fieldByName('siret');
        if (!input) return;
        var siret = normalize(input.value);
        if (siret === '') return;
        if (!isValidSiretLocal(siret)) {
            setStatus('error', 'SIRET invalide (14 chiffres, clé Luhn).');
            return;
        }
        input.value = siret;
        fillIfEmpty(fieldByName('vatNumber'), computeVatFrFromSiren(siret.substring(0, 9)));
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
    // Bootstrap
    // ------------------------------------------------------------------

    ready(function () {
        var radios = document.querySelectorAll('input[name="is_pro"]');
        if (!radios.length) return;

        removeDuplicateFields();
        reorderFields();
        applyCompanyReadonly();

        // État initial du SIRET : édition (valeur existante) → lecture seule
        // visible ; création → masqué (piloté par la sélection).
        var existingSiret = normalize((fieldByName('siret') || {}).value || '');
        setSiretMode(existingSiret !== '' ? 'readonly' : 'hidden');

        applyVisibility(isProChecked());

        radios.forEach(function (r) {
            r.addEventListener('change', function () { applyVisibility(isProChecked()); });
        });

        var sirenInput = fieldByName('siren');
        if (sirenInput) {
            sirenInput.addEventListener('blur', onSirenBlur);
            // Toute édition du SIREN réarme la recherche (permet un nouvel essai,
            // ex. après un rate-limit, ou pour corriger une saisie).
            sirenInput.addEventListener('input', function () { lastSearchedSiren = ''; });
        }
        var siretInput = fieldByName('siret');
        if (siretInput) {
            siretInput.addEventListener('blur', onSiretManualBlur);
        }
    });
})();
