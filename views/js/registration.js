/**
 * SNT Inscription Pro — front logic (Phase 2).
 *  - Réordonne le DOM : is_pro + champs pro tout en haut du bloc formulaire.
 *  - Toggle show/hide selon `is_pro` + gestion `required` dynamique.
 *  - Validation SIRET au blur : Luhn local puis appel AJAX INSEE.
 *  - Autofill company + vatNumber depuis la réponse INSEE.
 *  - Bouton « Utiliser mon SIREN » à côté du champ AFE.
 *  - Rend `company` readonly quand SNT_IP_COMPANY_EDITABLE = false.
 */
(function () {
    'use strict';

    if (window.console && window.console.debug) {
        window.console.debug('[SNT-IP] registration.js loaded');
    }

    var PRO_FIELDS = ['siret', 'company', 'vatNumber', 'afe'];

    var CFG = {
        validateUrl:     window.SNT_IP_VALIDATE_URL || '',
        companyEditable: !!window.SNT_IP_COMPANY_EDITABLE,
        afeRegex:        window.SNT_IP_AFE_REGEX || '',
        siretRequired:   !!window.SNT_IP_SIRET_REQUIRED,
    };

    var LUHN_EXCEPTIONS_SIREN = ['356000000']; // La Poste

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
        // Après removeDuplicateFields(), il ne doit rester que le nôtre.
        return document.querySelector('[name="' + name + '"]');
    }

    function wrapperOf(input) {
        if (!input) return null;
        return input.closest('.form-group') || input.parentElement;
    }

    /**
     * Container direct de l'input (sur PS classic, typiquement un `.col-md-*`
     * frère du label). C'est là qu'on veut coller les éléments qui doivent
     * apparaître sous l'input (statut, boutons).
     */
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
     * Supprime les inputs siret/company/vatNumber/afe hardcodés par le thème
     * (customer-form.tpl custom) en gardant uniquement ceux injectés par notre
     * hook `additionalCustomerFormFields`. On identifie les nôtres par le fait
     * qu'ils partagent le même parent direct que le radio `is_pro` — qui n'est
     * jamais présent dans un thème natif.
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
                // Si ce wrapper n'est pas un enfant direct du parent commun,
                // c'est un doublon (hardcodé thème) → on le retire.
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
    }

    // ------------------------------------------------------------------
    // Validation SIRET (Luhn local + AJAX INSEE)
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

    function computeVatFr(siret) {
        var siren = parseInt(siret.substring(0, 9), 10);
        if (isNaN(siren)) return '';
        var key = (12 + 3 * (siren % 97)) % 97;
        var padded = key < 10 ? '0' + key : String(key);
        return 'FR' + padded + siret.substring(0, 9);
    }

    function ensureStatusEl() {
        var siretInput = fieldByName('siret');
        if (!siretInput) return null;
        var container = inlineContainerOf(siretInput);
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

    function handleValidateResponse(data, localSiret) {
        var companyInput = fieldByName('company');
        var vatInput     = fieldByName('vatNumber');

        switch (data && data.status) {
            case 'ok':
                var label = data.company ? ('✓ ' + data.company) : '✓ SIRET vérifié';
                if (data.closed) label += ' (établissement fermé à l\'INSEE)';
                setStatus(data.closed ? 'pending' : 'ok', label);
                if (data.company) {
                    if (CFG.companyEditable) {
                        fillIfEmpty(companyInput, data.company);
                    } else {
                        forceFill(companyInput, data.company);
                    }
                }
                fillIfEmpty(vatInput, data.vat || computeVatFr(localSiret));
                break;

            case 'not_found':
                setStatus('error', 'SIRET inconnu à l\'INSEE.');
                break;

            case 'degraded':
                setStatus('pending', 'INSEE indisponible — saisissez votre raison sociale, elle sera vérifiée par nos équipes.');
                unlockCompanyForManual();
                fillIfEmpty(vatInput, data.vat || computeVatFr(localSiret));
                break;

            case 'invalid_format':
                setStatus('error', 'SIRET invalide.');
                break;

            case 'rate_limited':
                setStatus('error', 'Trop de tentatives, merci de patienter quelques instants.');
                break;

            case 'unavailable':
                // Service momentanément indisponible : on autorise la saisie
                // manuelle de la raison sociale plutôt que de bloquer le client.
                setStatus('pending', 'Vérification INSEE indisponible — saisissez votre raison sociale, elle sera vérifiée par nos équipes.');
                unlockCompanyForManual();
                fillIfEmpty(vatInput, data.vat || computeVatFr(localSiret));
                break;

            default:
                setStatus('error', 'Impossible de vérifier le SIRET.');
        }
    }

    /**
     * Fallback : quand l'INSEE ne peut confirmer le SIRET, on rend `company`
     * éditable (même si SNT_IP_COMPANY_EDITABLE=false) pour ne pas bloquer
     * l'inscription. Le serveur marque alors le compte « à vérifier ».
     */
    function unlockCompanyForManual() {
        var input = fieldByName('company');
        if (!input) return;
        input.removeAttribute('readonly');
        input.classList.remove('snt-ip-readonly');
        input.classList.add('snt-ip-manual');
    }

    function callValidate(siret) {
        if (!CFG.validateUrl) {
            setStatus('pending', 'SIRET localement valide (INSEE non configuré).');
            fillIfEmpty(fieldByName('vatNumber'), computeVatFr(siret));
            return;
        }
        setStatus('pending', 'Vérification INSEE…');
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
            handleValidateResponse(data, siret);
        };
        xhr.onerror = function () { setStatus('error', 'Erreur réseau.'); };
        xhr.send('siret=' + encodeURIComponent(siret) + '&ajax=1');
    }

    function onSiretBlur() {
        var input = fieldByName('siret');
        if (!input) return;
        var siret = normalize(input.value);
        if (siret === '') {
            setStatus('', '');
            return;
        }
        if (!isValidSiretLocal(siret)) {
            setStatus('error', 'SIRET invalide (14 chiffres, clé Luhn).');
            return;
        }
        input.value = siret;
        callValidate(siret);
    }

    // ------------------------------------------------------------------
    // Bouton « Utiliser mon SIREN » pour l'AFE
    // ------------------------------------------------------------------

    function injectAfeSirenButton() {
        var afeInput   = fieldByName('afe');
        var siretInput = fieldByName('siret');
        if (!afeInput || !siretInput) return;
        var container = inlineContainerOf(afeInput);
        if (!container || container.querySelector('.snt-ip-btn-siren')) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary btn-sm snt-ip-btn-siren';
        btn.textContent = 'Utiliser mon SIREN';
        btn.addEventListener('click', function () {
            var siren = normalize(siretInput.value).substring(0, 9);
            if (siren.length === 9) {
                afeInput.value = siren;
                afeInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        container.appendChild(btn);
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
        injectAfeSirenButton();
        applyVisibility(isProChecked());

        radios.forEach(function (r) {
            r.addEventListener('change', function () { applyVisibility(isProChecked()); });
        });

        var siretInput = fieldByName('siret');
        if (siretInput) {
            siretInput.addEventListener('blur', onSiretBlur);
        }
    });
})();
