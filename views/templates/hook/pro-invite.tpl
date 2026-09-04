{*
 * Bandeau d'invitation pour les clients professionnels « historiques » :
 * ils appartiennent au groupe « pro à compléter » (leur raison sociale a été
 * réinitialisée lors de la migration) mais ne sont pas encore passés par le
 * parcours pro du module (aucune ligne dans ps_snt_inscription_pro).
 * On les invite à compléter leurs informations pour la facturation électronique.
 *
 * Le lien « Ne plus afficher ce message » retire le client du groupe (il se
 * déclare particulier) via l'endpoint AJAX `dismiss` — le bandeau disparaît
 * alors définitivement pour lui.
 *
 * Variables : snt_ip_identity_url, snt_ip_dismiss_url, snt_ip_dismiss_token
 *}
<div class="alert snt-ip-pro-invite" style="background: #cff4fc;color:#333;border-radius:5px" role="alert">
  <p class="snt-ip-pro-invite__text">
    {l s='Vous êtes une entreprise ? Pour passer commande et bénéficier de la facturation électronique, nous vous invitons à compléter vos informations professionnelles (SIRET, raison sociale…).' mod='snt_inscription_pro'}
  </p>
  <a href="{$snt_ip_identity_url}" class="btn btn-primary snt-ip-pro-invite__cta">
    {l s='Mettre à jour mes informations' mod='snt_inscription_pro'}
  </a>
  <a href="#" class="btn btn-link snt-ip-pro-invite__dismiss" data-url="{$snt_ip_dismiss_url}"
    data-token="{$snt_ip_dismiss_token}">
    {l s='Ne plus afficher ce message' mod='snt_inscription_pro'}
  </a>
  <script>
    (function() {
      var s = document.currentScript;
      var box = s ? s.closest('.snt-ip-pro-invite') : null;

      // Page panier : le hook natif (displayShoppingCartFooter) insère le bandeau
      // en bas de #main. On le remonte juste après le titre « Panier » (.page-heading).
      // Détection par .cart-grid pour ne cibler QUE la page panier — sur le tunnel
      // (structure différente) le bandeau est déjà en haut du récap : on ne bouge rien.
      if (box) {
        var main = document.getElementById('main');
        if (main && main.querySelector('.cart-grid')) {
          var heading = main.querySelector('.page-heading');
          if (heading && heading.parentNode === main) {
            main.insertBefore(box, heading.nextSibling);
          } else {
            main.insertBefore(box, main.firstChild);
          }
        }
      }

      var btn = box ? box.querySelector('.snt-ip-pro-invite__dismiss') : null;
      if (!btn) { return; }
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        btn.setAttribute('disabled', 'disabled');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', btn.getAttribute('data-url'), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        // Dans tous les cas on masque le bandeau (l'action est sans risque et le
        // client a explicitement demandé à ne plus le voir).
        var hide = function() { if (box && box.parentNode) { box.parentNode.removeChild(box); } };
        xhr.onload = hide;
        xhr.onerror = hide;
        xhr.send('ajax=1&token=' + encodeURIComponent(btn.getAttribute('data-token')));
      });
    })();
  </script>
</div>