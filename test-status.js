/* ============================================================
   Booking API — Local test status panel
   Tiny script that fills in the checklist on the test page so
   you can see at a glance which globals are loaded.
   No external dependencies, no inline JS in test.html.
   ============================================================ */

(function () {
  "use strict";

  function $(id) { return document.getElementById(id); }

  function fmt(v) {
    if (v === undefined || v === null || v === "") return "—";
    return String(v);
  }

  // Wait until the widget has booted so the widget JS has had a chance
  // to pick up the globals. We don't strictly need to — the globals
  // are set in test-config.js before this script runs — but this keeps
  // the panel in sync with whatever the widget actually sees.
  function paint() {
    var apiEl = $("checkApi");
    var ppEl  = $("checkPp");
    if (apiEl) apiEl.textContent = fmt(window.API);
    if (ppEl)  ppEl.textContent  = fmt(window.KAFEH_PAYPAL_CLIENT_ID);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", paint);
  } else {
    paint();
  }
  // Run once more after the widget has booted, in case the widget
  // ever overwrites the globals.
  setTimeout(paint, 500);
})();
