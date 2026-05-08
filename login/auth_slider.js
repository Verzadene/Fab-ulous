/**
 * auth_slider.js
 * Shared animation controller for the three-panel auth slider used across
 * login.php, admin/admin_login.php, and register/register.html.
 *
 * Usage (at bottom of <body> on each auth page):
 *   <script src="../login/auth_slider.js"></script>
 *   <script>AuthSlider.init({ page: 'login' });</script>
 *
 * Valid page values: 'login' | 'admin' | 'register'
 */

(function (global) {
  'use strict';

  /** Canonical resting translateX (%) for each page. */
  const PAGE_OFFSET = {
    login:     0,
    admin:  -100,
    register: -200,
  };

  const SS_KEY = 'slideFrom';

  function snapTo(slider, pct) {
    slider.style.transition = 'none';
    slider.style.transform  = `translateX(${pct}%)`;
  }

  function slideTo(slider, pct) {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform  = `translateX(${pct}%)`;
      });
    });
  }

  function attachLinkListeners(slider, currentPage) {
    document.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', function (e) {
        const href = link.getAttribute('href');
        if (!href) return;

        let destPage = null;
        if (href.includes('admin_login.php'))                                 destPage = 'admin';
        else if (href.includes('register.html'))                              destPage = 'register';
        else if (href.includes('login.php') && !href.includes('admin_login')) destPage = 'login';

        if (!destPage || destPage === currentPage) return;
        if (href.includes('landing.html') || href.includes('forgot_password')) return;

        e.preventDefault();
        sessionStorage.setItem(SS_KEY, currentPage);
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform  = `translateX(${PAGE_OFFSET[destPage]}%)`;
        setTimeout(function () { window.location.href = link.href; }, 400);
      });
    });
  }

  function init(options) {
    const page = options && options.page;
    if (!Object.prototype.hasOwnProperty.call(PAGE_OFFSET, page)) {
      console.warn('[AuthSlider] Unknown page:', page);
      return;
    }

    const canonicalOffset = PAGE_OFFSET[page];

    // ─────────────────────────────────────────────────────────────────────
    // TWO-LISTENER PATTERN — handles all three load scenarios correctly:
    //
    // (A) bfcache restore  (Back/Forward button, event.persisted = true)
    //     → DOMContentLoaded does NOT fire.
    //     → Slider is frozen at whatever transform the outgoing animation
    //       left behind. Must snap back to canonical. No slide-in.
    //     → Handled by: pageshow (persisted = true) branch below.
    //
    // (B) Fresh load WITH slideFrom (arrived via auth link click)
    //     → DOMContentLoaded fires. sessionStorage has 'slideFrom'.
    //     → Snap to origin page's offset, animate to canonical.
    //     → Handled by: DOMContentLoaded branch below.
    //
    // (C) Fresh load WITHOUT slideFrom (direct URL / hard reload)
    //     → DOMContentLoaded fires. sessionStorage is empty.
    //     → Must snap directly to canonical — NO animation.
    //     → THIS was the critical missing fix for admin_login.php:
    //       without an inline style="transform: translateX(-100%)" on the
    //       HTML element, the CSS baseline is translateX(0), which places
    //       the slider on login's panel instead of admin's. Calling
    //       snapTo(canonicalOffset) here guarantees the correct panel is
    //       always visible regardless of how the page was reached.
    //     → Handled by: DOMContentLoaded branch below.
    // ─────────────────────────────────────────────────────────────────────

    // Handler for scenario (A): bfcache restores.
    window.addEventListener('pageshow', function (event) {
      if (!event.persisted) return; // Scenarios (B)/(C) handled by DOMContentLoaded.
      var slider = document.getElementById('authSlider');
      if (!slider) return;
      sessionStorage.removeItem(SS_KEY); // Clear any stale token.
      snapTo(slider, canonicalOffset);   // Snap back; no animation.
    });

    // Handler for scenarios (B) and (C): fresh loads and hard reloads.
    document.addEventListener('DOMContentLoaded', function () {
      var slider = document.getElementById('authSlider');
      if (!slider) return;

      var slideFrom = sessionStorage.getItem(SS_KEY);
      sessionStorage.removeItem(SS_KEY); // Clear immediately so Back → fresh-reload doesn't replay.

      if (slideFrom && Object.prototype.hasOwnProperty.call(PAGE_OFFSET, slideFrom) && slideFrom !== page) {
        // (B) Came from a known auth page — snap to their offset, slide to ours.
        snapTo(slider, PAGE_OFFSET[slideFrom]);
        slideTo(slider, canonicalOffset);
      } else {
        // (C) Direct URL / hard reload / unknown origin.
        // Snap to canonical NOW so the correct panel is visible immediately,
        // regardless of what the CSS default transform is.
        snapTo(slider, canonicalOffset);
      }

      attachLinkListeners(slider, page);
    });
  }

  global.AuthSlider = { init: init };

})(window);