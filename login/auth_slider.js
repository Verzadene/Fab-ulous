/**
 * auth_slider.js
 * Shared animation controller for the three-panel auth slider used across
 * login.php, admin/admin_login.php, and register/register.html.
 *
 * Each page passes its canonical (resting) slider offset so this module
 * can restore it correctly on any page load — including Back-Forward Cache
 * (bfcache) restores where DOMContentLoaded does NOT fire again.
 *
 * Usage (inline script at bottom of <body>):
 *   <script src="../login/auth_slider.js"></script>
 *   <script>AuthSlider.init({ page: 'login' });</script>
 *
 * Valid page values: 'login' | 'admin' | 'register'
 */

(function (global) {
  'use strict';

  /** Canonical resting translateX for each page. */
  const PAGE_OFFSET = {
    login:    0,
    admin:  -100,
    register: -200,
  };

  /**
   * sessionStorage key written before navigating away so the *destination*
   * page knows where to slide in from.
   */
  const SS_KEY = 'slideFrom';

  /**
   * Immediately set the slider to a position with no transition (snap).
   * Used to position before an animated slide-in begins.
   */
  function snapTo(slider, pct) {
    slider.style.transition = 'none';
    slider.style.transform  = `translateX(${pct}%)`;
  }

  /**
   * Animate the slider to a position over 400 ms.
   * Uses a double-rAF so the browser has committed the snap before the
   * transition is enabled.
   */
  function slideTo(slider, pct) {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform  = `translateX(${pct}%)`;
      });
    });
  }

  /**
   * Reset the slider to this page's canonical resting position instantly,
   * with no animation.  Used to fix any off-screen transform left behind by
   * a previous navigation (including bfcache restores).
   */
  function resetToCanonical(slider, page) {
    const offset = PAGE_OFFSET[page] ?? 0;
    slider.style.transition = 'none';
    slider.style.transform  = `translateX(${offset}%)`;
  }

  /**
   * Run the slide-in entrance animation if sessionStorage says we arrived
   * from a known auth page.  Clears the key immediately so Back won't
   * replay it.
   */
  function runSlideInIfNeeded(slider, currentPage) {
    const slideFrom = sessionStorage.getItem(SS_KEY);
    sessionStorage.removeItem(SS_KEY); // Always clear; prevents stale state.

    if (!slideFrom) return;

    const fromOffset = PAGE_OFFSET[slideFrom];
    if (fromOffset === undefined) return; // Unknown origin — skip animation.

    const toOffset = PAGE_OFFSET[currentPage] ?? 0;
    snapTo(slider, fromOffset);
    slideTo(slider, toOffset);
  }

  /**
   * Attach click listeners to all <a> tags that link to another auth page.
   * Writes sessionStorage before navigating so the destination knows to
   * animate in, and animates out before handing off to window.location.
   */
  function attachLinkListeners(slider, currentPage) {
    document.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', e => {
        const href = link.getAttribute('href');
        if (!href) return;

        let destPage = null;
        if (href.includes('admin_login.php'))                                destPage = 'admin';
        else if (href.includes('register.html'))                             destPage = 'register';
        else if (href.includes('login.php') && !href.includes('admin_login')) destPage = 'login';

        if (!destPage || destPage === currentPage) return;

        // Skip landing page and other non-auth links
        if (href.includes('landing.html') || href.includes('forgot_password')) return;

        e.preventDefault();
        sessionStorage.setItem(SS_KEY, currentPage);

        const destOffset = PAGE_OFFSET[destPage];
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform  = `translateX(${destOffset}%)`;

        setTimeout(() => { window.location.href = link.href; }, 400);
      });
    });
  }

  /**
   * Main entry point.  Call once per page.
   *
   * @param {object} options
   * @param {string} options.page  'login' | 'admin' | 'register'
   */
  function init(options) {
    const page = options?.page;
    if (!PAGE_OFFSET.hasOwnProperty(page)) {
      console.warn('[AuthSlider] Unknown page:', page);
      return;
    }

    // -------------------------------------------------------------------
    // pageshow fires on EVERY page display — fresh loads AND bfcache hits.
    // DOMContentLoaded only fires on fresh loads, so we rely on pageshow
    // as the single source of truth.
    // -------------------------------------------------------------------
    window.addEventListener('pageshow', function (event) {
      const slider = document.getElementById('authSlider');
      if (!slider) return;

      if (event.persisted) {
        // ---------------------------------------------------------------
        // bfcache restore: the page DOM is exactly as it was left, with
        // the slider potentially translated off-screen from the outgoing
        // animation.  Force-reset it to canonical, clear any stale key.
        // ---------------------------------------------------------------
        sessionStorage.removeItem(SS_KEY);
        resetToCanonical(slider, page);
      } else {
        // ---------------------------------------------------------------
        // Fresh load (or hard reload): run slide-in if we came from
        // another auth page, then wire up the outgoing click listeners.
        // ---------------------------------------------------------------
        runSlideInIfNeeded(slider, page);
        attachLinkListeners(slider, page);
      }
    });
  }

  // Expose on global so the inline <script> tag on each page can call it.
  global.AuthSlider = { init };

})(window);
