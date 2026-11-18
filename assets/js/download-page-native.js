/** Non-invasive progressive enhancement of a Zibll-native paid download page. */
(function () {
  'use strict';

  function init() {
    var owned = document.getElementById('jlwa-native-support');
    if (!owned) return;
    // No customer-service URL configured: omit our own empty wrapper.
    if (!owned.querySelector('.jld-help-panel')) {
      owned.remove();
      return;
    }
    // Move only WP Assistant's own markup. Never alter the theme's
    // purchase forms, payment callbacks, download buttons, or AJAX handlers.
    var nativeArticle = document.querySelector('body.jlwa-native-download .page-article');
    if (nativeArticle && !nativeArticle.contains(owned)) {
      nativeArticle.appendChild(owned);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
